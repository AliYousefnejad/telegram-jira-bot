<?php

namespace App\Services\Jira;

use App\Contracts\JiraServiceInterface;
use App\Enums\TaskOwner;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskOwnerService
{
    public function __construct(
        private readonly JiraServiceInterface $jira,
    ) {}

    /**
     * Look up the Jira custom field ID for the Owner field.
     */
    public function getOwnerFieldId(): ?string
    {
        $fieldName = (string) config('services.jira.custom_fields.owner', 'Owner');

        return $this->jira->customFieldId($fieldName);
    }

    /**
     * Check if the Owner custom field is defined on the Jira instance.
     */
    public function hasOwnerField(): bool
    {
        return $this->getOwnerFieldId() !== null;
    }

    /**
     * Resolve the corresponding TaskOwner enum for a given Jira status name.
     */
    public function resolveOwnerForStatus(string $statusName): ?TaskOwner
    {
        $normalizedStatus = mb_strtoupper(trim($statusName));
        if ($normalizedStatus === '') {
            return null;
        }

        /** @var array<string, string> $statusMap */
        $statusMap = config('services.jira.status_owner_map', []);

        foreach ($statusMap as $mappedStatus => $ownerVal) {
            if (mb_strtoupper(trim((string) $mappedStatus)) === $normalizedStatus) {
                return TaskOwner::fromJiraOption($ownerVal);
            }
        }

        return null;
    }

    /**
     * Update the Owner field on a Jira issue directly. This is system work, so it always uses the
     * bot's token, even while a user acts with their own token.
     */
    public function setOwner(string $taskKey, TaskOwner|string $owner): bool
    {
        return app(JiraCredentials::class)->asBot(fn (): bool => $this->writeOwner($taskKey, $owner));
    }

    private function writeOwner(string $taskKey, TaskOwner|string $owner): bool
    {
        $ownerEnum = $owner instanceof TaskOwner ? $owner : TaskOwner::fromJiraOption($owner);

        if ($ownerEnum === null) {
            Log::warning("Owner assignment failed for {$taskKey}: unmappable owner value '{$owner}'");

            return false;
        }

        $fieldId = $this->getOwnerFieldId();

        if ($fieldId === null) {
            Log::info("Owner assignment skipped for {$taskKey}: Owner custom field not defined on Jira");

            return false;
        }

        $optionId = $this->jira->optionIdForCode($taskKey, $fieldId, $ownerEnum->value);

        if ($optionId === null) {
            Log::warning("Owner assignment skipped for {$taskKey}: option '{$ownerEnum->value}' not found for field {$fieldId}");

            return false;
        }

        try {
            $this->jira->updateIssueField($taskKey, $fieldId, ['id' => $optionId]);
            Log::info("Set owner for {$taskKey} to {$ownerEnum->value} (optionId: {$optionId})");

            return true;
        } catch (Throwable $e) {
            Log::error("Failed to update Owner field for {$taskKey}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Automatically sync the Owner field based on a target status name.
     */
    public function syncOwnerForStatus(string $taskKey, string $statusName): ?TaskOwner
    {
        $targetOwner = $this->resolveOwnerForStatus($statusName);

        if ($targetOwner === null) {
            return null;
        }

        $this->setOwner($taskKey, $targetOwner);

        return $targetOwner;
    }

    /**
     * Automatically sync the Owner field for an issue by reading its current status.
     *
     * @param  array<string, mixed>|null  $issueData
     */
    public function syncOwnerForIssue(string $taskKey, ?array $issueData = null): ?TaskOwner
    {
        if ($issueData === null) {
            try {
                $issueData = $this->jira->getIssue($taskKey);
            } catch (Throwable $e) {
                Log::warning("Could not fetch issue {$taskKey} to sync owner: ".$e->getMessage());

                return null;
            }
        }

        if ($issueData === null) {
            return null;
        }

        $statusName = (string) ($issueData['fields']['status']['name'] ?? '');

        if ($statusName === '') {
            return null;
        }

        return $this->syncOwnerForStatus($taskKey, $statusName);
    }

    /**
     * Batch sync owners for issues matching JQL or default active issues.
     *
     * @return array{total: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function syncOwnersBatch(?string $jql = null, int $limit = 100): array
    {
        if ($jql === null || trim($jql) === '') {
            $defaultProject = (string) config('services.jira.default_project');
            $projectClause = $defaultProject !== '' ? "project = '{$defaultProject}' AND " : '';
            $jql = "{$projectClause}status is not EMPTY ORDER BY updated DESC";
        }

        $results = [
            'total' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $response = $this->jira->getIssues($jql, $limit, 0, true);
            $issues = $response['issues'] ?? [];
            $results['total'] = count($issues);

            foreach ($issues as $issue) {
                $key = (string) ($issue['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $statusName = (string) ($issue['fields']['status']['name'] ?? '');
                $targetOwner = $this->resolveOwnerForStatus($statusName);

                if ($targetOwner === null) {
                    $results['skipped']++;

                    continue;
                }

                $fieldId = $this->getOwnerFieldId();
                if ($fieldId !== null) {
                    $currentRaw = $issue['fields'][$fieldId] ?? null;
                    $currentOwner = TaskOwner::fromJiraOption($currentRaw);
                    if ($currentOwner === $targetOwner) {
                        $results['skipped']++;

                        continue;
                    }
                }

                $success = $this->setOwner($key, $targetOwner);
                if ($success) {
                    $results['updated']++;
                } else {
                    $results['errors'][] = "Failed to update owner for {$key}";
                }
            }
        } catch (Throwable $e) {
            $results['errors'][] = 'Batch query failed: '.$e->getMessage();
        }

        return $results;
    }
}
