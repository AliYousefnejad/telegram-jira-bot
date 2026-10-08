<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\TaskPriority;
use App\Services\Jira\TaskService;
use Tests\TestCase;

class TaskServicePriorityTest extends TestCase
{
    public function test_created_task_is_sent_to_jira_with_the_selected_priority(): void
    {
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage');

        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (
                string $projectKey,
                string $summary,
                string $issueType,
                ?string $description,
                ?string $assigneeUserName,
                ?string $reporterUserName,
                array $extraFields,
            ): bool {
                return $projectKey === 'SCRUM'
                    && $summary === 'Fix login bug'
                    && $issueType === 'Task'
                    && ($extraFields['priority']['name'] ?? '') === 'High';
            })
            ->andReturn(['key' => 'SCRUM-9']);
        // No local sync when the freshly created issue cannot be fetched.
        $jira->shouldReceive('getIssue')->andReturn(null);

        app(TaskService::class)->createTaskFromFlow(555, 'SCRUM', 'Fix login bug', 0, TaskPriority::High);
    }

    public function test_created_task_without_priority_uses_no_priority_field(): void
    {
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage');

        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(fn (string $p, string $s, string $t, ?string $d, ?string $a, ?string $r, array $e): bool => $e === [])
            ->andReturn(['key' => 'SCRUM-10']);
        $jira->shouldReceive('getIssue')->andReturn(null);

        app(TaskService::class)->createTaskFromFlow(555, 'SCRUM', 'No priority', 0, null);
    }
}
