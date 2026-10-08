<?php

namespace App\Enums;

enum ReportType: string
{
    /** A problem reported by an end user. */
    case UserBug = 'user_bug';

    /** A problem with the admin panel itself. */
    case AdminPanelProblem = 'admin_panel_problem';

    /** An urgent external requirement (a regulator, partner, management, ...). */
    case ForceMajeure = 'force_majeure';

    public function issueType(): string
    {
        return match ($this) {
            self::UserBug => 'Bug',
            self::AdminPanelProblem, self::ForceMajeure => 'Task',
        };
    }

    /**
     * English label shown in Telegram messages for the "نوع" (type) field.
     * This replaces the raw Jira issue type name in user-facing messages.
     */
    public function telegramType(): string
    {
        return match ($this) {
            self::UserBug => 'User Issue / Bug',
            self::AdminPanelProblem => 'Admin Issue',
            self::ForceMajeure => 'Force-Major Requirement',
        };
    }

    public function label(): string
    {
        return __('reports.type.'.$this->value);
    }

    /**
     * Emoji-free label used in the Jira issue summary. Emojis are Telegram-only
     * decoration; the Jira payload stays clean so reports read naturally there.
     */
    public function jiraLabel(): string
    {
        return __('reports.jira.type.'.$this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::UserBug => '🪲',
            self::AdminPanelProblem => '🖥️',
            self::ForceMajeure => '⚡',
        };
    }

    /**
     * Detects whether a task summary was built from a report (its label is the
     * summary prefix, see ReportFlowService::buildSummary) and returns the type.
     * Both the emoji label (Telegram) and the plain jira label (legacy + new
     * Jira summaries) are recognized.
     */
    public static function fromSummary(string $summary): ?self
    {
        foreach (self::cases() as $type) {
            if (str_starts_with($summary, $type->label()) || str_starts_with($summary, $type->jiraLabel())) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Returns the summary text after the report-type prefix (empty when the
     * summary is not a report). Handles both the emoji and plain prefixes so
     * the offset always matches the stored summary.
     */
    public static function summaryRef(string $summary): string
    {
        foreach (self::cases() as $type) {
            if (str_starts_with($summary, $type->label())) {
                return mb_substr($summary, mb_strlen($type->label()));
            }

            if (str_starts_with($summary, $type->jiraLabel())) {
                return mb_substr($summary, mb_strlen($type->jiraLabel()));
            }
        }

        return '';
    }
}
