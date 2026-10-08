<?php

namespace App\Http\Resources\WebApp;

use App\Enums\TaskImpact;
use App\Enums\TaskSeverity;
use App\Models\JiraUser;
use App\Resources\TaskResource;
use App\Services\Jira\JiraService;
use App\Support\DisplayDate;

/**
 * Shapes Jira issues for the Mini App. Assignees are mapped to their linked
 * Telegram username through jira_users.
 */
class TaskPayload
{
    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    public static function collection(array $issues): array
    {
        $handles = self::telegramHandles(array_map(fn (array $issue): ?array => $issue['fields']['assignee'] ?? null, $issues));

        return array_map(fn (array $issue): array => self::summary($issue, $handles), $issues);
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    public static function detail(array $issue): array
    {
        $handles = self::telegramHandles([$issue['fields']['assignee'] ?? null]);

        return self::summary($issue, $handles) + [
            'description' => TaskResource::toPlainText($issue['fields']['description'] ?? ''),
            'reporter' => $issue['fields']['reporter']['displayName'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<string, string>  $handles
     * @return array<string, mixed>
     */
    private static function summary(array $issue, array $handles): array
    {
        $fields = $issue['fields'] ?? [];
        $assignee = $fields['assignee'] ?? null;
        $severity = TaskSeverity::fromJiraOption(TaskResource::customFieldValue($fields, 'severity'));
        $impact = TaskImpact::fromJiraOption(TaskResource::customFieldValue($fields, 'impact'));

        return [
            'key' => (string) ($issue['key'] ?? ''),
            'summary' => (string) ($fields['summary'] ?? ''),
            'type' => $fields['issuetype']['name'] ?? null,
            'status' => $fields['status']['name'] ?? null,
            'severity' => $severity?->value,
            'severity_label' => $severity?->label(),
            'impact' => $impact?->value,
            'impact_label' => $impact?->label(),
            'owner' => TaskResource::customFieldValue($fields, 'owner'),
            'category' => TaskResource::customFieldValue($fields, 'category'),
            'assignee' => is_array($assignee) ? [
                'name' => $assignee['displayName'] ?? JiraService::userId($assignee),
                'telegram' => $handles[JiraService::userId($assignee)] ?? null,
            ] : null,
            'created' => $fields['created'] ?? null,
            'created_jalali' => self::jalali($fields['created'] ?? null),
            'updated' => $fields['updated'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $users
     * @return array<string, string> Jira user id => "@username"
     */
    private static function telegramHandles(array $users): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (?array $user): string => $user === null ? '' : JiraService::userId($user),
            $users,
        ))));

        if ($ids === []) {
            return [];
        }

        return JiraUser::query()->whereIn('jira_account_id', $ids)
            ->whereNotNull('telegram_admin_id')
            ->whereNotNull('telegram_username')
            ->where('telegram_username', '!=', '')
            ->pluck('telegram_username', 'jira_account_id')
            ->map(fn (string $username): string => '@'.ltrim($username, '@'))
            ->all();
    }

    private static function jalali(mixed $iso): ?string
    {
        return DisplayDate::format($iso);
    }
}
