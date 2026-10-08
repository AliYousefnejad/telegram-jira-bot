<?php

namespace App\Resources;

use App\Contracts\JiraServiceInterface;
use App\Enums\ReportType;
use App\Enums\TaskImpact;
use App\Enums\TaskOwner;
use App\Enums\TaskSeverity;
use App\Resources\Concerns\BuildsListText;
use App\Support\DisplayDate;
use App\Support\JiraUrl;
use App\Support\JiraWiki;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

class TaskResource
{
    use BuildsListText;

    private const LIST_TRUNCATE = 40;

    public static function toList(array $task): string
    {
        $fields = $task['fields'] ?? [];
        $status = is_array($fields['status'] ?? null) ? (string) ($fields['status']['name'] ?? '—') : '—';
        $summary = (string) ($fields['summary'] ?? '');

        return '• '.$task['key'].' ['.$status.'] '.$summary;
    }

    /**
     * Single-line manage-tasks row: report tasks carry their type badge and the
     * user reference, plain tasks keep key + status + truncated summary.
     */
    public static function toManageListRow(array $task): string
    {
        $fields = $task['fields'] ?? [];
        $key = (string) ($task['key'] ?? '?');
        $summary = (string) ($fields['summary'] ?? '');
        $status = is_array($fields['status'] ?? null) ? (string) ($fields['status']['name'] ?? '—') : '—';
        $type = is_array($fields['issuetype'] ?? null) ? (string) ($fields['issuetype']['name'] ?? '—') : '—';

        $report = ReportType::fromSummary($summary);
        $typeLabel = $report !== null ? $report->telegramType() : $type;

        if ($report !== null) {
            $userRef = self::cleanRef(ReportType::summaryRef($summary));

            return trim('• '.$key.' ['.$status.'] '.$typeLabel.($userRef !== '' ? ' '.$userRef : ''));
        }

        return trim('• '.$key.' ['.$status.'] '.$typeLabel.' '.mb_substr($summary, 0, self::LIST_TRUNCATE));
    }

    public static function toDetail(array $task): string
    {
        $fields = $task['fields'] ?? [];
        $key = (string) ($task['key'] ?? '');
        $summary = (string) ($fields['summary'] ?? '');
        $status = is_array($fields['status'] ?? null) ? (string) ($fields['status']['name'] ?? '—') : '—';
        $priority = is_array($fields['priority'] ?? null) ? (string) ($fields['priority']['name'] ?? '—') : '—';
        $type = is_array($fields['issuetype'] ?? null) ? (string) ($fields['issuetype']['name'] ?? '—') : '—';
        $assignee = is_array($fields['assignee'] ?? null) ? (string) ($fields['assignee']['displayName'] ?? '—') : '—';
        $reporter = is_array($fields['reporter'] ?? null) ? (string) ($fields['reporter']['displayName'] ?? '—') : '—';
        $createdJalaali = self::jalaliDateTime($fields['created'] ?? null);
        $updated = self::jalaliDateTime($fields['updated'] ?? null);

        $report = ReportType::fromSummary($summary);
        $reportRef = $report !== null ? self::cleanRef(ReportType::summaryRef($summary)) : '';

        $text = '🏷️ '.$key.': '.($report !== null ? (trim($reportRef) !== '' ? $reportRef : $report->label()) : $summary)."\n";
        $text .= "━━━━━━━━━━━━━━━━\n";
        $text .= __('task.detail.type', ['value' => $report !== null ? $report->telegramType() : $type])."\n";
        $text .= __('task.detail.status', ['value' => $status])."\n";

        if ($report === ReportType::UserBug) {
            $category = self::customFieldValue($fields, 'category');
            if ($category !== null && $category !== '') {
                $text .= __('task.detail.category', ['value' => $category])."\n";
            }
        }

        $owner = TaskOwner::fromJiraOption(self::customFieldValue($fields, 'owner'));
        $text .= __('task.detail.owner', ['value' => $owner?->label() ?? '—'])."\n";

        // Priority hidden from detail view — uncomment to restore.
        // $text .= __('task.detail.priority', ['value' => $priority])."\n";

        $severity = TaskSeverity::fromJiraOption(self::customFieldValue($fields, 'severity'));
        $text .= __('task.detail.severity', ['value' => $severity?->label() ?? '—'])."\n";

        $impact = TaskImpact::fromJiraOption(self::customFieldValue($fields, 'impact'));
        $text .= __('task.detail.impact', ['value' => $impact?->label() ?? '—'])."\n";

        $text .= __('task.detail.assignee', ['value' => $assignee])."\n";
        $text .= __('task.detail.reporter', ['value' => $reporter])."\n";
        $text .= __('task.detail.created_jalaali', ['value' => $createdJalaali])."\n";
        $text .= __('task.detail.updated', ['value' => $updated])."\n";

        $labels = $fields['labels'] ?? [];
        if (is_array($labels) && ! empty($labels)) {
            $text .= __('task.detail.labels', ['value' => implode(', ', array_map('strval', $labels))])."\n";
        }

        $projectKey = is_array($fields['project'] ?? null) ? (string) ($fields['project']['key'] ?? '') : '';
        if ($projectKey !== '') {
            $text .= __('task.detail.link', ['value' => JiraUrl::browse($key)])."\n";
        }

        $text .= "━━━━━━━━━━━━━━━━\n";

        if ($report !== null && trim($reportRef) !== '') {
            $text .= __('task.detail.report_user', ['value' => $reportRef])."\n";
        }

        $description = self::toPlainText($fields['description'] ?? null);

        if (trim($description) !== '') {
            $text .= __('task.detail.description', ['description' => Str::limit($description, 300)]);
        }

        return $text;
    }

    /** The display date (see DisplayDate), or "—". */
    public static function jalaliDateTime(mixed $iso): string
    {
        return DisplayDate::format($iso) ?? '—';
    }

    private static function cleanRef(string $ref): string
    {
        return trim((string) preg_replace('/^:\s*/u', '', $ref));
    }

    public static function customFieldValue(array $fields, string $action): ?string
    {
        $fieldId = app(JiraServiceInterface::class)->customFieldId(config("services.jira.custom_fields.{$action}"));

        if ($fieldId === null) {
            return null;
        }

        $value = $fields[$fieldId] ?? null;

        // Single-select fields store {"value": …}; multi-select fields
        // (e.g. Impact) store a list of such objects — take the first one.
        if (is_array($value)) {
            if (isset($value[0]) && is_array($value[0])) {
                $value = $value[0]['value'] ?? null;
            } else {
                $value = $value['value'] ?? null;
            }
        }

        return $value === null ? null : (string) $value;
    }

    public static function toPlainText(mixed $description): string
    {
        if (is_array($description)) {
            $description = self::extractPlainText($description);
        }

        return JiraWiki::stripHeadings(is_string($description) ? $description : '');
    }

    private static function extractPlainText(array $node): string
    {
        $text = '';
        if (isset($node['text'])) {
            $text .= $node['text'];
        }
        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= self::extractPlainText($child);
            }
        }

        return $text;
    }
}
