<?php

namespace App\Services\Domain;

use App\Contracts\JiraServiceInterface;
use App\Enums\EditAction;
use App\Enums\ReportType;
use App\Enums\TaskImpact;
use App\Enums\TaskOwner;
use App\Enums\TaskSeverity;
use RuntimeException;

/**
 * Writes one task edit to Jira. Shared by the bot's Manage tasks (staged edits) and the Mini App.
 * Values: status = transition id, assignee = Jira user id (null/'' unassigns), owner/severity/impact =
 * their code (L1, S1, I1), category = option id, description/comment = text.
 */
class TaskEditService
{
    public function __construct(private JiraServiceInterface $jira) {}

    public function apply(string $taskKey, string $action, ?string $value): void
    {
        switch ($action) {
            case EditAction::Status->value:
                $this->jira->transitionIssue($taskKey, (string) $value);

                return;

            case EditAction::Assignee->value:
                if ($value === null || $value === '') {
                    $this->jira->updateIssueField($taskKey, 'assignee', ['name' => null]);
                } else {
                    $this->jira->assignIssue($taskKey, $value);
                }

                return;

            case EditAction::Impact->value:
            case EditAction::Severity->value:
                $enumClass = $action === 'impact' ? TaskImpact::class : TaskSeverity::class;

                if ($enumClass::tryFrom((string) $value) === null) {
                    throw new RuntimeException("Unmappable {$action} value '{$value}'");
                }

                // Impact is a multi-select field (array payload), severity a single-select (bare object).
                // Both resolve options by their code prefix, immune to emoji/decoration in option labels.
                $fieldId = $this->customField($action);
                $optionId = $this->optionId($taskKey, $fieldId, $action, (string) $value);
                $this->jira->updateIssueField($taskKey, $fieldId, $action === 'impact' ? [['id' => $optionId]] : ['id' => $optionId]);

                return;

            case EditAction::Owner->value:
                if (TaskOwner::tryFrom((string) $value) === null) {
                    throw new RuntimeException("Unmappable owner value '{$value}'");
                }

                $fieldId = $this->customField('owner');
                $this->jira->updateIssueField($taskKey, $fieldId, ['id' => $this->optionId($taskKey, $fieldId, 'owner', (string) $value)]);

                return;

            case EditAction::Category->value:
                if (! $this->hasCategory($taskKey)) {
                    return;
                }

                $fieldId = $this->customField('category', 'Ticket Category');

                try {
                    $this->jira->updateIssueField($taskKey, $fieldId, ['id' => (string) $value]);
                } catch (\Throwable) {
                    $this->jira->updateIssueField($taskKey, $fieldId, [['id' => (string) $value]]);
                }

                return;

            case EditAction::Description->value:
                $this->jira->updateDescription($taskKey, (string) $value);

                return;

            case EditAction::Comment->value:
                $this->jira->addComment($taskKey, (string) $value);

                return;
        }

        throw new RuntimeException("Unknown edit '{$action}'");
    }

    /** Only user bug reports carry a category. */
    public function hasCategory(string $taskKey): bool
    {
        $issue = $this->jira->getIssue($taskKey);

        return ReportType::fromSummary((string) ($issue['fields']['summary'] ?? '')) === ReportType::UserBug;
    }

    /**
     * The category options of a user bug report, or null when the task has no category.
     *
     * @return array<int, array{id: string, value: string}>|null
     */
    public function categoryOptions(string $taskKey): ?array
    {
        $fieldId = $this->jira->customFieldId((string) config('services.jira.custom_fields.category', 'Ticket Category'));

        if ($fieldId === null || ! $this->hasCategory($taskKey)) {
            return null;
        }

        $options = $this->jira->fieldAllowedOptions($taskKey, $fieldId);

        if (empty($options)) {
            $projectKey = strtoupper(explode('-', $taskKey)[0] ?: (config('services.jira.default_project') ?: 'TS'));
            $options = $this->jira->getCreateFieldAllowedOptions($projectKey, $fieldId, 'Bug');
        }

        return array_values(array_map(
            fn (array $option): array => ['id' => (string) ($option['id'] ?? ''), 'value' => (string) ($option['value'] ?? '')],
            array_filter($options, 'is_array'),
        ));
    }

    private function customField(string $action, ?string $default = null): string
    {
        $fieldId = $this->jira->customFieldId((string) config("services.jira.custom_fields.{$action}", $default));

        if ($fieldId === null) {
            throw new RuntimeException("The {$action} field is not defined on the Jira instance");
        }

        return $fieldId;
    }

    private function optionId(string $taskKey, string $fieldId, string $action, string $code): string
    {
        $optionId = $this->jira->optionIdForCode($taskKey, $fieldId, $code);

        if ($optionId === null) {
            throw new RuntimeException("Option for {$action} '{$code}' not found on the Jira instance");
        }

        return $optionId;
    }
}
