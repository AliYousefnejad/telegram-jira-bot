<?php

namespace App\Resources;

use App\Enums\EditAction;
use App\Enums\ReportType;
use App\Enums\TaskOwner;
use App\Resources\Concerns\FormatsRtlText;
use App\Services\Jira\TaskOwnerService;
use App\Support\DisplayDate;
use App\Support\JiraUrl;
use App\Support\JiraWiki;

class ReportsMessageResource
{
    use FormatsRtlText;

    public static function adminOnly(): string
    {
        return self::rtl(__('reports.admin_only'));
    }

    public static function invalidType(): string
    {
        return self::rtl(__('reports.invalid_type'));
    }

    public static function userInfoRequired(): string
    {
        return self::rtl(__('reports.user_info_required'));
    }

    public static function forceMajeureWarning(): string
    {
        return self::rtl(__('reports.force_majeure_warning'));
    }

    /** @param  array<string, mixed>  $data */
    public static function userInfoReview(array $data): string
    {
        $lines = [];

        foreach (self::userFieldLabels() as $key => $label) {
            $value = trim((string) ($data[$key] ?? ''));
            $lines[] = self::rtl(__('reports.user_info_review.line', [
                'label' => $label,
                'value' => $value !== '' ? self::ltr($value) : __('reports.user_info_review.empty'),
            ]));
        }

        return self::rtl(__('reports.user_info_review.title')."\n".implode("\n", $lines));
    }

    /** @return array<string, string>  report data key => Jira-safe user field label */
    public static function userFieldLabels(): array
    {
        return [
            'user_name' => __('reports.jira.user_name_label'),
            'user_identifier' => __('reports.jira.user_identifier_label'),
            'user_mobile' => __('reports.jira.user_mobile_label'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function created(array $issue, array $data = []): string
    {
        $key = (string) ($issue['key'] ?? '?');
        $link = config('services.jira.base_url') !== ''
            ? self::ltr(__('reports.created_link', ['url' => preg_replace('#^https?://#', '', JiraUrl::browse($key))]))
            : '';

        $fields = $issue['fields'] ?? [];
        $type = is_array($fields['issuetype'] ?? null) ? (string) ($fields['issuetype']['name'] ?? '—') : '—';
        $assignee = is_array($fields['assignee'] ?? null) ? (string) ($fields['assignee']['displayName'] ?? '') : '';

        $extraLines = [];

        foreach (self::userFieldLabels() as $fieldKey => $label) {
            $value = trim((string) ($data[$fieldKey] ?? ''));

            if ($value !== '') {
                $extraLines[] = __('reports.created_user_field', ['label' => $label, 'value' => self::ltr($value)]);
            }
        }

        if ($assignee !== '') {
            $extraLines[] = __('reports.created_assignee', ['assignee' => self::ltr($assignee)]);
        }

        $report = ReportType::tryFrom((string) ($data['type'] ?? ''));

        $categoryLine = '';

        if ($report === ReportType::UserBug) {
            $category = TaskResource::customFieldValue($fields, 'category');
            if ($category !== null && $category !== '') {
                $categoryLine = "\n".(string) __('task.detail.category', ['value' => self::ltr($category)]);
            }
        }

        $jalaali = DisplayDate::format($fields['created'] ?? null) ?? '—';

        // The Jira summary is emoji-free (see ReportType::jiraLabel); re-add the
        // type badge on Telegram only so the confirmation keeps its character.
        $summary = $report !== null
            ? $report->badge().' '.(string) ($fields['summary'] ?? '')
            : (string) ($fields['summary'] ?? '');

        // Use the Telegram-specific type label when the report type is known;
        // fall back to the raw Jira issue type name for non-report tasks.
        $displayType = $report !== null ? $report->telegramType() : $type;

        $owner = TaskOwner::fromJiraOption(TaskResource::customFieldValue($fields, 'owner'))
            ?? app(TaskOwnerService::class)->resolveOwnerForStatus((string) ($fields['status']['name'] ?? 'Raised'));

        $extraSection = $extraLines === [] ? '' : "\n━━━━━━━━━━━━━━━━\n".implode("\n", $extraLines);

        $text = self::rtl(__('reports.created', [
            'key' => self::ltr($key),
            'summary' => self::ltr($summary),
            'type' => self::ltr($displayType),
            'status' => self::ltr((string) ($fields['status']['name'] ?? '—')),
            'category' => $categoryLine,
            'owner' => self::ltr($owner?->label() ?? '—'),
            'jalaali' => self::ltr($jalaali),
            'reporter' => self::ltr((string) ($fields['reporter']['displayName'] ?? '—')),
            'extra' => $extraSection,
        ]));

        $description = trim(JiraWiki::stripHeadings((string) ($data['description'] ?? '')));

        if ($description !== '') {
            $text .= "\n\n".self::rtl(__('reports.created_description', ['description' => $description]));
        }

        if ($link !== '') {
            $text .= "\n\n━━━━━━━━━━━━━━━━\n".$link;
        }

        return $text;
    }

    public static function createdError(): string
    {
        return CommonMessageResource::internalError();
    }

    public static function openHeader(string $projectKey, int $page): string
    {
        return self::rtl(__('reports.manage.open_header', [
            'project' => self::ltr($projectKey),
            'page' => self::ltr((string) $page),
        ]));
    }

    public static function noneOpen(): string
    {
        return self::rtl(__('reports.manage.none_open'));
    }

    public static function filterLabel(): string
    {
        return self::rtl(__('reports.manage.filter_label'));
    }

    public static function actionLabel(string $action): string
    {
        return self::rtl(__("reports.manage.action.{$action}"));
    }

    public static function pickStatusTransition(): string
    {
        return self::rtl(__('reports.manage.pick_status_transition'));
    }

    public static function pickAssignee(): string
    {
        return self::rtl(__('reports.manage.pick_assignee'));
    }

    public static function enterDescription(string $taskKey): string
    {
        return self::rtl(__('reports.manage.enter_description', ['key' => self::ltr($taskKey)]));
    }

    public static function enterReportDescription(string $taskKey): string
    {
        return self::rtl(__('reports.manage.enter_report_description', ['key' => self::ltr($taskKey)]));
    }

    public static function enterComment(string $taskKey): string
    {
        return self::rtl(__('reports.manage.enter_comment', ['key' => self::ltr($taskKey)]));
    }

    public static function fieldUnavailable(): string
    {
        return self::rtl(__('reports.manage.unavailable'));
    }

    public static function stagedHeader(string $taskKey): string
    {
        return self::rtl(__('reports.manage.staged_header', ['key' => self::ltr($taskKey)]));
    }

    public static function nothingStaged(): string
    {
        return self::rtl(__('reports.manage.nothing_staged'));
    }

    public static function stagedDiscarded(string $taskKey): string
    {
        return self::rtl(__('reports.manage.staged_discarded', ['key' => self::ltr($taskKey)]));
    }

    /** @param  array<string, array{ok: bool, label: string}>  $results */
    public static function appliedSummary(string $taskKey, array $results): string
    {
        $lines = '';

        foreach (EditAction::values() as $action) {
            if (! isset($results[$action])) {
                continue;
            }

            $result = $results[$action];
            $icon = $result['ok'] ? '✅' : '❌';
            $lines .= self::ltr($icon.' '.__("reports.manage.field.{$action}").': '.$result['label'])."\n";
        }

        return self::rtl(__('reports.manage.applied_summary', [
            'key' => self::ltr($taskKey),
            'lines' => rtrim($lines),
        ]));
    }
}
