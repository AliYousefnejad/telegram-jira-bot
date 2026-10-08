<?php

namespace App\Enums;

enum CallbackAction: string
{
    /** Separator buttons that perform no action. */
    case Noop = 'noop';

    /** Cancels the currently active flow conversation. */
    case FlowCancel = 'flow_cancel';

    /** Steps the active flow conversation back to its previous visible step. */
    case FlowBack = 'flow_back';

    /** Prefix for all callbacks belonging to a flow command, e.g. flow_create_project_SCRUM. */
    case Flow = 'flow_';

    /** Prefix for the admin project picker offered mid-flow, e.g. flowpick_create_project_. */
    case FlowPick = 'flowpick_';

    /** Paginated task list of a project, e.g. page_DEMO_2. */
    case Page = 'page_';

    /** Paginated task detail list of a project, e.g. dpage_DEMO_3. */
    case DetailPage = 'dpage_';

    /** Opens the user picker for assigning a task, e.g. assign_DEMO-1. */
    case Assign = 'assign_';

    /** Opens the task detail view, e.g. detail_DEMO-1. */
    case Detail = 'detail_';

    /** Confirms linking a Jira account, e.g. linkpick_a@b.com_jsmith. */
    case LinkPick = 'linkpick_';

    /** Dismisses the Jira account picker shown when several accounts match an email. */
    case LinkCancel = 'linkcancel';

    /** Opens the status transition picker for a task, e.g. status_DEMO-1. */
    case Status = 'status_';

    /** Opens the timeline view for a task, e.g. timeline_DEMO-1. */
    case Timeline = 'timeline_';

    /** Opens the board/sprint picker for pushing a task, e.g. push_DEMO-1. */
    case Push = 'push_';

    /** Picks a board while pushing a task to a sprint, e.g. pickboard_DEMO-1_42. */
    case PickBoard = 'pickboard_';

    /** Picks a user while assigning a task, e.g. pickassign_DEMO-1_jsmith. */
    case PickAssign = 'pickassign_';

    /** Picks a status transition for a task, e.g. pickstatus_DEMO-1_71. */
    case PickStatus = 'pickstatus_';

    /** Picks a sprint for a task, e.g. picksprint_DEMO-1_9. */
    case PickSprint = 'picksprint_';

    /** Opens the admin task-management section, e.g. rptmenu_tasks. */
    case RptMenu = 'rptmenu_';

    /** Opens a project's open-task list, e.g. rptproj_PE. */
    case RptProject = 'rptproj_';

    /** Filters the open-task list by status id, e.g. rptfilter_10306. */
    case RptFilter = 'rptfilter_';

    /** Paginates the open-task list, e.g. rptpage_prev / rptpage_next. */
    case RptPage = 'rptpage_';

    /** Opens the edit menu of a task, e.g. rpttask_DEMO-1. */
    case RptTask = 'rpttask_';

    /** Starts an edit action on a task, e.g. rptact_assignee_DEMO-1. */
    case RptAct = 'rptact_';

    /** Stages an edit value on a task, e.g. rptset_status_DEMO-1_31. */
    case RptSet = 'rptset_';

    /** Applies every staged edit of a task, e.g. rptconfirm_DEMO-1. */
    case RptConfirm = 'rptconfirm_';

    /** Discards every staged edit of a task, e.g. rptdiscard_DEMO-1. */
    case RptDiscard = 'rptdiscard_';

    /** Leaves a field edit picker, keeping staged edits, e.g. rptskip_DEMO-1. */
    case RptSkip = 'rptskip_';

    /** User-info staging for description edit on UserBug tasks, e.g. rptdescinfo_user_name_DEMO-1. */
    case RptDescUserInfo = 'rptdescinfo_';

    /** Timeline selection option, e.g. rpttimeline_status or rpttimeline_key. */
    case RptTimeline = 'rpttimeline_';

    /** Cancels viewing a task in manage-tasks and returns to the open-task list, e.g. rptcanceltask_DEMO-1. */
    case RptCancelTask = 'rptcanceltask_';

    /** Cancels an edit section/picker and returns to the task, e.g. rptcancelsection_DEMO-1. */
    case RptCancelSection = 'rptcancelsection_';

    /** Cancels the manage-tasks interactive session. */
    case RptCancel = 'rptcancel';

    /** Leaves the manage-tasks / timeline session entirely and restores the main menu. */
    case RptExit = 'rptexit';
}
