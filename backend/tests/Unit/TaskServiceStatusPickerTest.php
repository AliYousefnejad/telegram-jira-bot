<?php

namespace Tests\Unit;

use App\Enums\CallbackAction;
use App\Services\Jira\JiraService;
use App\Services\Jira\SyncService;
use App\Services\Jira\TaskService;
use App\Services\Telegram\TelegramMessenger;
use Tests\TestCase;

class TaskServiceStatusPickerTest extends TestCase
{
    public function test_detail_status_button_only_offers_statuses_of_the_projects_board(): void
    {
        $this->mock(SyncService::class);

        $jira = $this->mock(JiraService::class);
        $jira->shouldReceive('getProjectBoard')
            ->with('DEMO')
            ->andReturn(7);
        $jira->shouldReceive('getBoardConfiguration')
            ->with(7)
            ->andReturn(['statuses' => ['Raised', 'Under Triage', 'Verified', 'Closed'], 'project_key' => 'DEMO']);
        $jira->shouldReceive('getIssueTransitions')
            ->with('DEMO-1')
            ->andReturn([
                ['id' => '13', 'name' => 'Verify', 'to' => ['name' => 'verified']],
                ['id' => '11', 'name' => 'Raise it', 'to' => ['name' => 'Raised']],
                ['id' => '14', 'name' => 'Close it', 'to' => ['name' => 'Closed']],
                ['id' => '12', 'name' => 'Triage it', 'to' => ['name' => 'under triage']],
            ]);

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                if ($chatId !== 555 || ! isset($extra['reply_markup'])) {
                    return false;
                }

                $rows = json_decode($extra['reply_markup'], true)['inline_keyboard'];
                $data = [];

                foreach ($rows as $row) {
                    if (isset($row[0]['callback_data'])) {
                        $data[] = $row[0]['callback_data'];
                    }
                }

                // Buttons follow the board's column order even though Jira
                // returned the transitions scrambled ("In Progress" dropped);
                // the Cancel row stays last.
                return array_slice($data, 0, 4) === [
                    'pickstatus_DEMO-1_11',
                    'pickstatus_DEMO-1_12',
                    'pickstatus_DEMO-1_13',
                    'pickstatus_DEMO-1_14',
                ]
                    && count($rows) === 5
                    && $rows[4][0]['callback_data'] === CallbackAction::FlowCancel->value;
            });

        app(TaskService::class)->sendStatusPickerForKeyboard(555, 'DEMO-1');
    }

    public function test_detail_status_button_falls_back_to_raw_transitions_without_a_board(): void
    {
        $this->mock(SyncService::class);

        $jira = $this->mock(JiraService::class);
        $jira->shouldReceive('getProjectBoard')->with('DEMO')->andReturn(null);
        $jira->shouldReceive('getIssueTransitions')
            ->with('DEMO-1')
            ->andReturn([
                ['id' => '31', 'name' => 'Start Progress'],
            ]);

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                return str_contains($extra['reply_markup'] ?? '', 'pickstatus_DEMO-1_31');
            });

        app(TaskService::class)->sendStatusPickerForKeyboard(555, 'DEMO-1');
    }
}
