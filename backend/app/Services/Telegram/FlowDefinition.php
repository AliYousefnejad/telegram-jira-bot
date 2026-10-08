<?php

namespace App\Services\Telegram;

use App\Enums\FlowCommand;
use App\Enums\FlowStep;

class FlowDefinition
{
    /**
     * Step name constants — kept as simple strings to preserve callback-data
     * compatibility.  The {@see FlowStep} enum mirrors these values
     * and should be preferred in new code that references step identifiers.
     */
    public function steps(FlowCommand $command, bool $projectFirst = false): ?array
    {
        $flows = [
            // Tasks flow disabled — uncomment to restore.
            // FlowCommand::Tasks->value => [
            //     ['type' => 'button', 'step' => 'project', 'picker' => 'project'],
            //     ['type' => 'execute'],
            // ],
            // Sprints disabled — uncomment to restore.
            // FlowCommand::Sprints->value => [
            //     ['type' => 'button', 'step' => 'board', 'picker' => 'board'],
            //     ['type' => 'execute'],
            // ],
            FlowCommand::Link->value => [
                ['type' => 'text', 'step' => 'email', 'prompt' => 'flowEnterEmail'],
                ['type' => 'execute'],
            ],

            // Create task disabled — uncomment to restore.
            // FlowCommand::Create->value => [
            //     ['type' => 'button', 'step' => 'project', 'picker' => 'project'],
            //     ['type' => 'text', 'step' => 'summary', 'prompt' => 'flowEnterSummary'],
            //     ['type' => 'text', 'step' => 'description', 'prompt' => 'flowEnterTaskDescription', 'min' => 3],
            //     ['type' => 'button', 'step' => 'priority', 'picker' => 'priority'],
            //     ['type' => 'execute'],
            // ],
            // Assign disabled — uncomment to restore.
            // FlowCommand::Assign->value => [
            //     ['type' => 'button', 'step' => 'task', 'picker' => 'task'],
            //     ['type' => 'button', 'step' => 'user', 'picker' => 'user'],
            //     ['type' => 'execute'],
            // ],
            // Unassign disabled — uncomment to restore.
            // FlowCommand::Unassign->value => [
            //     ['type' => 'button', 'step' => 'task', 'picker' => 'task'],
            //     ['type' => 'execute'],
            // ],
            // Change status disabled — uncomment to restore.
            // FlowCommand::Status->value => [
            //     ['type' => 'button', 'step' => 'board', 'picker' => 'board'],
            //     ['type' => 'button', 'step' => 'task', 'picker' => 'task'],
            //     ['type' => 'button', 'step' => 'status', 'picker' => 'status'],
            //     ['type' => 'execute'],
            // ],
            // Push to sprint disabled — uncomment to restore.
            // FlowCommand::Push->value => [
            //     ['type' => 'button', 'step' => 'project', 'picker' => 'project'],
            //     ['type' => 'button', 'step' => 'task', 'picker' => 'task'],
            //     ['type' => 'button', 'step' => 'board', 'picker' => 'board'],
            //     ['type' => 'button', 'step' => 'sprint', 'picker' => 'sprint'],
            //     ['type' => 'execute'],
            // ],
            // Task details disabled — uncomment to restore.
            // FlowCommand::TaskDetail->value => [
            //     ['type' => 'button', 'step' => 'project', 'picker' => 'project'],
            //     ['type' => 'execute'],
            // ],
            FlowCommand::Report->value => [
                // Project picker disabled — TS is the default project.
                // ['type' => 'button', 'step' => 'project', 'picker' => 'project'],
                ['type' => 'button', 'step' => 'type', 'picker' => 'report_type'],
                // Interactive staging screen for user-identifying fields —
                // the admin fills one or more fields via a picker menu,
                // then confirms to proceed to the description step.
                ['type' => 'button', 'step' => 'user_info_staging', 'picker' => 'user_info_staging', 'when' => ['step' => 'type', 'equals' => 'user_bug']],
                [
                    'type' => 'button',
                    'step' => 'category',
                    'picker' => 'category',
                    'when' => [
                        ['step' => '_cat_ok', 'equals' => true],
                        ['step' => 'type', 'equals' => 'user_bug'],
                    ],
                ],
                ['type' => 'text', 'step' => 'description', 'prompt' => 'flowEnterDescription', 'min' => 10, 'short_hint' => 'reports.description_too_short'],
                // Priority picking disabled — uncomment to restore.
                // ['type' => 'button', 'step' => 'priority', 'picker' => 'priority'],
                // Severity / Impact / Assignee removed from report creation — uncomment to restore.
                // [
                //     'type' => 'button',
                //     'step' => 'severity',
                //     'picker' => 'severity',
                //     // Only offered while the Jira instance defines the field.
                //     'when' => ['step' => '_sev_ok', 'equals' => true],
                // ],
                // [
                //     'type' => 'button',
                //     'step' => 'impact',
                //     'picker' => 'impact',
                //     'when' => ['step' => '_imp_ok', 'equals' => true],
                // ],
                // ['type' => 'button', 'step' => 'assignee', 'picker' => 'assignee'],
                ['type' => 'execute'],
            ],
            FlowCommand::SetTopicName->value => [
                ['type' => 'text', 'step' => 'topic_name', 'prompt' => 'flowEnterTopicName'],
                ['type' => 'execute'],
            ],
        ];

        if (! isset($flows[$command->value])) {
            return null;
        }

        if ($projectFirst && in_array($command, FlowCommand::projectFirstCandidates(), true)) {
            array_unshift($flows[$command->value], ['type' => 'button', 'step' => 'project', 'picker' => 'project']);
        }

        return $flows[$command->value];
    }
}
