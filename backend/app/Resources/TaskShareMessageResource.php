<?php

namespace App\Resources;

use App\Enums\TaskImpact;
use App\Enums\TaskSeverity;
use App\Resources\Concerns\FormatsRtlText;
use App\Services\Jira\JiraService;
use App\Support\JiraUrl;
use App\Support\TelegramMention;

/**
 * The message the Mini App's "send to this chat" posts for a task (HTML parse mode).
 * Used both for a task from Manage tasks and for a freshly created report.
 */
class TaskShareMessageResource
{
    use FormatsRtlText;

    /**
     * @param  array<string, mixed>  $issue  a Jira issue as returned by getIssue()
     * @param  array<string, mixed>  $sharedBy  the Telegram user from the Mini App launch data
     */
    public static function forIssue(array $issue, array $sharedBy): string
    {
        $key = (string) ($issue['key'] ?? '');
        $fields = $issue['fields'] ?? [];
        $severity = TaskSeverity::fromJiraOption(TaskResource::customFieldValue($fields, 'severity'));
        $impact = TaskImpact::fromJiraOption(TaskResource::customFieldValue($fields, 'impact'));
        $owner = TaskResource::customFieldValue($fields, 'owner');
        $assignee = is_array($fields['assignee'] ?? null) ? $fields['assignee'] : null;

        $type = (string) ($fields['issuetype']['name'] ?? '');

        $lines = [
            self::t('webapp.share.title', [
                'key' => self::ltr('<b>'.e($key).'</b>'),
                'status' => self::statusEmoji($fields['status'] ?? null).' '.self::ltr(e((string) ($fields['status']['name'] ?? '—'))),
            ]),
            self::t('webapp.share.summary', ['summary' => '<b>'.e((string) ($fields['summary'] ?? '')).'</b>']),
            self::t('webapp.share.rule'),
        ];

        if ($type !== '') {
            $lines[] = self::t('webapp.share.type', ['type' => self::ltr(e($type))]);
        }

        $lines[] = self::t('webapp.share.severity', ['dot' => self::levelDot($severity?->value), 'severity' => self::ltr(e($severity?->label() ?? '—'))]);
        $lines[] = self::t('webapp.share.impact', ['dot' => self::levelDot($impact?->value), 'impact' => self::ltr(e($impact?->label() ?? '—'))]);
        $lines[] = self::t('webapp.share.assignee', ['assignee' => $assignee === null
            ? e(self::t('webapp.share.unassigned'))
            : TelegramMention::forJiraUser(JiraService::userId($assignee), (string) ($assignee['displayName'] ?? ''))]);

        if ($owner !== null && $owner !== '') {
            $lines[] = self::t('webapp.share.owner', ['owner' => self::ltr(e($owner))]);
        }

        $lines[] = self::t('webapp.share.created', ['date' => self::ltr(TaskResource::jalaliDateTime($fields['created'] ?? null))]);
        $lines[] = self::t('webapp.share.rule');

        if (config('services.jira.base_url') !== '') {
            $lines[] = '<a href="'.e(JiraUrl::browse($key)).'">'.e(self::t('webapp.share.open_in_jira')).'</a>';
        }

        $lines[] = self::t('webapp.share.shared_by', ['user' => TelegramMention::forTelegramUser($sharedBy)]);

        return self::rtl(implode("\n", $lines));
    }

    /** S1/I1 red … S4/I4 green; white when the field is empty. */
    private static function levelDot(?string $level): string
    {
        return match (substr((string) $level, 1)) {
            '1' => '🔴',
            '2' => '🟠',
            '3' => '🟡',
            '4' => '🟢',
            default => '⚪',
        };
    }

    /** Jira's status category: to do, in progress, done. */
    private static function statusEmoji(mixed $status): string
    {
        $category = is_array($status) && is_array($status['statusCategory'] ?? null) ? ($status['statusCategory']['key'] ?? '') : '';

        return match ($category) {
            'done' => '✅',
            'indeterminate' => '🔄',
            default => '🆕',
        };
    }

    /** @param  array<string, string>  $replace */
    private static function t(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
