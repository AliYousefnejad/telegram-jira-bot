<?php

namespace App\Enums;

enum FlowStep: string
{
    /** Project key selection (picker). */
    case Project = 'project';

    /** Board selection (picker). */
    case Board = 'board';

    /** Sprint selection (picker). */
    case Sprint = 'sprint';

    /** Task selection (picker). */
    case Task = 'task';

    /** Jira user selection (picker). */
    case User = 'user';

    /** Report type selection (picker). */
    case ReportType = 'report_type';

    /** Report: interactive staging of the user-info fields (picker). */
    case UserInfoStaging = 'user_info_staging';

    /** Report: user-reference kind selection (phone / username / identifier - picker). */
    case UserRefType = 'usertype';

    /** Report: staging review of the collected user-info fields (picker). */
    case UserInfoReview = 'user_info_review';

    /** Priority selection (picker). */
    case Priority = 'priority';

    /** Severity selection (picker). */
    case Severity = 'severity';

    /** Impact selection (picker). */
    case Impact = 'impact';

    /** Assignee selection (picker). */
    case Assignee = 'assignee';

    /** Status / transition selection (picker). */
    case Status = 'status';

    /** Category selection (picker). */
    case Category = 'category';

    // ── Text-input steps ──────────────────────────────────────────

    /** Jira account email (text input). */
    case Email = 'email';

    /** Task summary (text input). */
    case Summary = 'summary';

    /** Task description (text input). */
    case Description = 'description';

    /** Report: end-user full name (text input). */
    case UserName = 'user_name';

    /** Report: end-user identifier / employee id (text input). */
    case UserIdentifier = 'user_identifier';

    /** Report: end-user mobile / phone (text input). */
    case UserMobile = 'user_mobile';

    // ── Internal flags (stored in data, never rendered as pickers) ─

    /** Whether the Jira instance defines the severity custom field. */
    case SevOk = '_sev_ok';

    /** Whether the Jira instance defines the impact custom field. */
    case ImpOk = '_imp_ok';

    /** Whether the Jira instance defines the category custom field. */
    case CatOk = '_cat_ok';

    /** Tracked flow message IDs for cleanup. */
    case FlowMessages = '_flow_messages';
}
