<?php

namespace App\Enums;

enum FlowCommand: string
{
    /** Lists the tasks of a project. */
    case Tasks = 'tasks';

    /** Lists the sprints of a board. */
    case Sprints = 'sprints';

    /** Links the user's Jira account by email. */
    case Link = 'link';

    /** Creates a new task in a project. */
    case Create = 'create';

    /** Assigns a task to a Jira user. */
    case Assign = 'assign';

    /** Removes the assignee of a task. */
    case Unassign = 'unassign';

    /** Changes the status of a task via a transition. */
    case Status = 'status';

    /** Pushes a task into a sprint. */
    case Push = 'push';

    /** Shows a paginated task list with per-task detail buttons. */
    case TaskDetail = 'task_detail';

    /**
     * Registers a problem / bug / force-majeure report as a Jira issue.
     * Admin-only; see UpdateProcessor and the reports services.
     */
    case Report = 'report';

    /** Conversational flow: asks for a topic name and applies it. */
    case SetTopicName = 'set_topic_name';

    public static function projectFirstCandidates(): array
    {
        return [self::Assign, self::Unassign];
    }
}
