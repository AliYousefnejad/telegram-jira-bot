<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Enums\FlowCommand;
use App\Enums\TaskPriority;
use App\Exceptions\JiraTimeoutException;
use App\Resources\CommonMessageResource;
use App\Resources\FlowMessageResource;
use App\Resources\TaskMessageResource;
use App\Services\AccountLinkService;
use App\Services\Jira\TaskService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\BotInfoService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\Reports\ReportFlowService;
use App\Support\CallbackData;
use Mockery;
use Mockery\MockInterface;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Tests\TestCase;

class FlowServiceTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);

        parent::tearDown();
    }

    public function test_cancel_callback_clears_conversation_and_notifies_user(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        Telegram::swap($api);

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->addTrackedMessage(self::USER_ID, 101);
        $this->assertTrue($conversation->isActive(self::USER_ID));

        $this->mockFlowMessenger()
            ->shouldReceive('deleteMessagesQuietly')
            ->once()
            ->with(self::CHAT_ID, [101])
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::flowCancelled(), Mockery::type('array'));

        $this->handleCallback(CallbackData::flow('cancel'));

        // The whole flow state (including tracked messages) is gone.
        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_cancel_without_tracked_messages_still_confirms_cancellation(): void
    {
        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);

        $this->mockFlowMessenger()
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::flowCancelled(), Mockery::type('array'));

        $this->handleCallback(CallbackData::flow('cancel'));

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_info_callbacks_are_rendered_by_bot_info_service(): void
    {
        $this->allowAnsweringCallbackQuery();

        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')->once()->with(self::CHAT_ID, false, 0, null, self::USER_ID);
        $info->shouldReceive('start')->once()->with(self::CHAT_ID, 'Alice', false, 0, null, self::USER_ID);

        // About / Projects / Boards removed from the info callbacks.
        foreach (['start', 'help'] as $payload) {
            $this->handleCallback(CallbackData::flow($payload));
        }
    }

    public function test_start_callback_greets_the_user_by_first_name(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(BotInfoService::class)
            ->shouldReceive('start')
            ->once()
            ->with(self::CHAT_ID, 'Alice', false, 0, null, self::USER_ID);

        $this->handleCallback(CallbackData::flow('start'));
    }

    public function test_link_callback_starts_the_link_flow(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->once()
            ->withArgs(function (int $chatId, int $userId, string $text): bool {
                return $chatId === self::CHAT_ID
                    && $userId === self::USER_ID
                    && str_contains($text, __('flow.enter.email'));
            });

        $this->handleCallback(CallbackData::flow('link'));

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame(FlowCommand::Link->value, $state['command']);
        $this->assertSame(0, $state['step']);
    }

    public function test_task_menu_callback_maps_to_the_task_detail_flow(): void
    {
        // Feature temporarily disabled — restore with the commented
        // tasks/task-details blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $this->mockTaskProjectPicker('flow_task_detail_project_');

        $this->handleCallback(CallbackData::flow('task'));

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame(FlowCommand::TaskDetail->value, $state['command']);
    }

    public function test_completing_the_link_flow_links_the_account(): void
    {
        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Link, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, ['email' => 'alice@example.com']);

        $this->mock(AccountLinkService::class)
            ->shouldReceive('linkAccount')
            ->once()
            ->with(self::CHAT_ID, 'alice@example.com', self::USER_ID, false, 0, null, '');

        $this->handleCallback(CallbackData::flow(FlowCommand::Link->value).'_email_alice@example.com');

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_mid_flow_project_pick_renders_the_task_picker_for_that_project(): void
    {
        // Push to sprint is temporarily disabled — restore this test together
        // with the commented Push flow in FlowDefinition & FlowService.
        $this->markTestSkipped('Push to sprint is currently disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnTrue();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Push, self::USER_ID, self::CHAT_ID, 42, true);

        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('getIssues')
            ->once()
            ->withArgs(function (string $jql): bool {
                return str_contains($jql, 'project = "SCRUM"');
            })
            ->andReturn(['issues' => [], 'hasMore' => false]);

        $this->mockFlowMessenger()
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text, array $extra): bool => $text === TaskMessageResource::noTasks(''));

        $this->handleCallback(CallbackData::flowPickProject('push').'SCRUM');

        $freshState = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame('SCRUM', $freshState['data']['project']);
    }

    public function test_unknown_payload_with_no_active_conversation_is_ignored(): void
    {
        $this->allowAnsweringCallbackQuery();

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('sendTrackedMessage');

        $this->handleCallback('totally_unknown_payload');

        $this->assertFalse(app(ConversationManager::class)->isActive(self::USER_ID));
    }

    public function test_selection_with_unknown_step_is_ignored_safely(): void
    {
        // Feature temporarily disabled — restore with the commented
        // tasks/task-details blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Tasks, self::USER_ID, self::CHAT_ID, 42, false);

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('sendTrackedMessage');

        // flow_tasks_project_SCRUM matches the command prefix and completes
        // the single-step flow: the project selection is stored and executed.
        $this->mockJiraTasks(['issues' => [['key' => 'DEMO-1', 'fields' => ['summary' => 'First']]]]);
        $this->mock(TaskService::class)->shouldReceive('sendTasksForProject')->once();

        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_create_flow_prompts_for_description_after_summary(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->once()
            ->withArgs(function (int $chatId, int $userId, string $text): bool {
                return str_contains($text, __('flow.enter.task_description'));
            });

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Create, [
            'project' => 'SCRUM',
            'summary' => 'Fix login bug',
        ]);

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame(2, $state['step']);
    }

    public function test_selecting_a_priority_marks_it_without_advancing_the_flow(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->startCreateConversationAtPriorityStep();

        $this->mockFlowMessenger()
            ->shouldReceive('editMessageText')
            ->once()
            ->withArgs(function (int $chatId, int $messageId, string $text, array $extra): bool {
                if ($chatId !== self::CHAT_ID || $messageId !== 42 || $text !== __('priority.select')) {
                    return false;
                }

                $rows = json_decode($extra['reply_markup'], true)['inline_keyboard'];

                return $rows[1][0]['text'] === '✅ High'
                    && end($rows)[0]['callback_data'] === 'flow_create_priority_confirm';
            });

        $this->handleCallback(CallbackData::flowStepPrefix('create', 'priority').'High');

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame(3, $state['step']);
        $this->assertSame('High', $state['data']['priority']);
    }

    public function test_selecting_another_priority_replaces_the_previous_selection(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->startCreateConversationAtPriorityStep(TaskPriority::Low);

        $this->mockFlowMessenger()
            ->shouldReceive('editMessageText')
            ->once()
            ->withArgs(function (int $chatId, int $messageId, string $text, array $extra): bool {
                $rows = json_decode($extra['reply_markup'], true)['inline_keyboard'];

                return $rows[0][0]['text'] === '✅ Highest'
                    && $rows[3][0]['text'] === 'Low';
            });

        $this->handleCallback(CallbackData::flowStepPrefix('create', 'priority').'Highest');

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame('Highest', $state['data']['priority']);
    }

    public function test_invalid_priority_is_rejected_and_does_not_advance(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->startCreateConversationAtPriorityStep();

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text, array $extra): bool => str_contains($text, __('flow.invalid_input')));
        $messenger->shouldNotReceive('editMessageText');

        $this->handleCallback(CallbackData::flowStepPrefix('create', 'priority').'Critical');

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame(3, $state['step']);
        $this->assertArrayNotHasKey('priority', $state['data']);
    }

    public function test_confirming_the_priority_creates_the_task_with_it(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->startCreateConversationAtPriorityStep(TaskPriority::High);

        $this->mock(TaskService::class)
            ->shouldReceive('createTaskFromFlow')
            ->once()
            ->with(self::CHAT_ID, 'SCRUM', 'Fix login bug', self::USER_ID, TaskPriority::High, 'Detailed description of the problem');

        $this->handleCallback(CallbackData::flowStepPrefix('create', 'priority').'confirm');

        $this->assertFalse(app(ConversationManager::class)->isActive(self::USER_ID));
    }

    public function test_button_start_while_a_flow_is_active_informs_user_and_keeps_state(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Tasks, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, ['project' => 'DEMO']);

        $this->mockFlowMessenger()
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::alreadyWaiting(), Mockery::type('array'));

        $this->handleCallback(CallbackData::flow('create'));

        // The active waiting state survived the second click untouched.
        $state = $conversation->get(self::USER_ID);
        $this->assertSame(FlowCommand::Tasks->value, $state['command']);
        $this->assertSame('DEMO', $state['data']['project']);
    }

    public function test_a_completed_flow_cannot_be_executed_again_by_duplicate_callbacks(): void
    {
        // Feature temporarily disabled — restore with the commented
        // tasks/task-details blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Tasks, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, ['project' => 'SCRUM']);

        $this->mockJiraTasks(['issues' => [['key' => 'DEMO-1', 'fields' => ['summary' => 'First']]]]);
        $tasks = $this->mock(TaskService::class);
        $tasks->shouldReceive('sendTasksForProject')->once();

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldNotReceive('sendMessage');

        // First delivery executes the flow and consumes the waiting state.
        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');
        $this->assertFalse($conversation->isActive(self::USER_ID));

        // A duplicate of the same callback arrives: no state left, no action,
        // and no confusing "expired" notice either.
        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');

        $tasks->shouldHaveReceived('sendTasksForProject')->once();
        $messenger->shouldNotHaveReceived('sendMessage');
    }

    public function test_a_genuinely_stale_click_after_the_recently_consumed_window_gets_the_expired_notice(): void
    {
        // Feature temporarily disabled — restore with the commented
        // tasks/task-details blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Tasks, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, ['project' => 'SCRUM']);

        $this->mockJiraTasks(['issues' => [['key' => 'DEMO-1', 'fields' => ['summary' => 'First']]]]);
        $tasks = $this->mock(TaskService::class);
        $tasks->shouldReceive('sendTasksForProject')->once();

        $messenger = $this->mockFlowMessenger();

        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');
        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');

        // Much later the user clicks the old button again: now it deserves
        // an explanation instead of silence.
        $this->travel(2)->minutes();

        $messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::expired(), Mockery::type('array'));

        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');

        $messenger->shouldHaveReceived('sendMessage')->once();
    }

    public function test_timeout_during_flow_execution_deletes_tracked_messages_and_notifies(): void
    {
        // Feature temporarily disabled — restore with the commented
        // tasks/task-details blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Tasks, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, ['project' => 'SCRUM']);
        $conversation->addTrackedMessage(self::USER_ID, 101);

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        Telegram::swap($api);

        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('getIssues')
            ->andThrow(new JiraTimeoutException('timed out'));

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('deleteMessagesQuietly')
            ->once()
            ->with(self::CHAT_ID, [101])
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, CommonMessageResource::timeoutError(), Mockery::type('array'));

        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'SCRUM');

        // Waiting state is gone; only the timeout message was sent.
        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_status_flow_starts_with_the_board_picker(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('getBoards')
            ->andReturn([['id' => 7, 'name' => 'DEMO Board', 'type' => 'scrum']]);

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage')->once();

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Status);

        $state = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame(FlowCommand::Status->value, $state['command']);
        $this->assertSame(0, $state['step']);
    }

    public function test_status_flow_scopes_tasks_to_the_selected_boards_project(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();
        // Even admins skip the project picker: the board scopes the tasks.
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnTrue();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Status, self::USER_ID, self::CHAT_ID, 42, false);

        $tasks = $this->mock(TaskService::class);
        $tasks->shouldNotReceive('showProjectPicker');

        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getBoardConfiguration')
            ->with(7)
            ->andReturn(['statuses' => ['Raised'], 'project_key' => 'DEMO']);
        $jira->shouldReceive('getIssues')
            ->once()
            ->withArgs(function (string $jql): bool {
                return str_contains($jql, 'project = "DEMO"');
            })
            ->andReturn(['issues' => [['key' => 'DEMO-1', 'fields' => ['summary' => 'First']]]]);

        $this->mockFlowMessenger()->shouldReceive('sendTrackedMessage')->once();

        $this->handleCallback(CallbackData::flowStepPrefix('status', 'board').'7');

        $state = $conversation->get(self::USER_ID);
        $this->assertSame(1, $state['step']);
        $this->assertSame('7', $state['data']['board']);
    }

    public function test_status_picker_shows_only_board_statuses_reachable_via_transitions(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Status, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, ['board' => '7']);

        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getBoardConfiguration')
            ->with(7)
            ->andReturn(['statuses' => ['Raised', 'Verified'], 'project_key' => 'DEMO']);
        $jira->shouldReceive('getIssueTransitions')
            ->with('DEMO-1')
            ->andReturn([
                ['id' => '11', 'name' => 'Raise it', 'to' => ['name' => 'Raised']],
                ['id' => '12', 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
                ['id' => '13', 'name' => 'Verify', 'to' => ['name' => 'verified']],
            ]);

        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->once()
            ->withArgs(function (int $chatId, int $userId, string $text, array $extra): bool {
                $rows = json_decode($extra['reply_markup'], true)['inline_keyboard'];
                $data = [];

                foreach ($rows as $row) {
                    if (isset($row[0]['callback_data'])) {
                        $data[] = $row[0]['callback_data'];
                    }
                }

                return $text === FlowMessageResource::flowPickStatus()
                    && in_array('flow_status_status_11', $data, true)
                    && in_array('flow_status_status_13', $data, true)
                    && ! in_array('flow_status_status_12', $data, true);
            });

        $this->handleCallback(CallbackData::flowStepPrefix('status', 'task').'DEMO-1');
    }

    public function test_selecting_a_board_status_transitions_via_its_jira_transition_id(): void
    {
        // Feature temporarily disabled — restore with the commented
        // create/unassign/status blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Status, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 3, ['board' => '7', 'task' => 'DEMO-1', 'status' => '11']);

        $tasks = $this->mock(TaskService::class);
        $tasks->shouldReceive('changeStatus')
            ->once()
            ->with(self::CHAT_ID, 'DEMO-1', '11');

        $this->handleCallback(CallbackData::flowStepPrefix('status', 'status').'11');

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_stale_flow_button_click_informs_the_user_the_action_expired(): void
    {
        $this->allowAnsweringCallbackQuery();

        // No active conversation: the button belongs to an old message.
        $this->mockFlowMessenger()
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::expired(), Mockery::type('array'));

        $this->handleCallback(CallbackData::flowStepPrefix('create', 'priority').'High');

        $this->assertFalse(app(ConversationManager::class)->isActive(self::USER_ID));
    }

    public function test_execution_with_incomplete_data_is_reported_instead_of_silence(): void
    {
        // Feature temporarily disabled — restore with the commented
        // tasks/task-details blocks in FlowDefinition & FlowService.
        $this->markTestSkipped('Feature temporarily disabled.');

        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Tasks, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 1, []);

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text, array $extra): bool => str_contains($text, __('flow.invalid_input')));

        $this->handleCallback(CallbackData::flowStepPrefix('tasks', 'project').'');

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_report_flow_skips_user_info_steps_for_non_bug_reports(): void
    {
        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Report, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 0, ['project' => 'SCRUM']);

        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->once()
            ->withArgs(function (int $chatId, int $userId, string $text): bool {
                return str_contains($text, __('reports.enter.description'));
            });

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'admin_panel_problem');

        // The staging step (index 1) is skipped for non-bug reports; the
        // category step (index 2) is skipped when _cat_ok is not set;
        // the description step (index 3) is next.
        $this->assertSame(3, $conversation->get(self::USER_ID)['step']);
    }

    public function test_report_flow_opens_staging_screen_for_bug_reports(): void
    {
        $this->allowAnsweringCallbackQuery();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Report, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 0, ['project' => 'SCRUM']);

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage')
            ->once()
            ->withArgs(function (int $chatId, int $userId, string $text): bool {
                return str_contains($text, __('reports.user_info_review.title'));
            });

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');

        $this->assertSame(1, $conversation->get(self::USER_ID)['step']);
    }

    public function test_report_flow_field_button_prompts_for_its_value(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');

        // Still on the staging step (index 1) waiting for the field value, and
        // the field prompt was shown.
        $this->assertSame(1, $conversation->get(self::USER_ID)['step']);
        $this->assertSame('user_name', $conversation->get(self::USER_ID)['data']['_staging_field'] ?? null);
        $this->assertTrue(
            collect($messages)->contains(fn (string $text): bool => str_contains($text, __('reports.jira.user_name_label')))
        );
    }

    public function test_report_flow_entering_a_field_returns_to_staging(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'ali');

        // Back on the staging screen, still on the staging step, value saved,
        // and the next field can be filled.
        $this->assertSame(1, $conversation->get(self::USER_ID)['step']);
        $this->assertSame('ali', $conversation->get(self::USER_ID)['data']['user_name'] ?? null);
        $this->assertSame('', $conversation->get(self::USER_ID)['data']['_staging_field'] ?? null);
        $this->assertTrue(
            collect($messages)->contains(fn (string $text): bool => str_contains($text, __('reports.user_info_review.title')))
        );
    }

    public function test_report_flow_back_from_a_field_prompt_returns_to_staging(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'ali');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_mobile');
        $messages = [];

        $this->handleCallback(CallbackAction::FlowBack->value);

        $state = $conversation->get(self::USER_ID);
        $this->assertSame(1, $state['step']);
        $this->assertSame('', $state['data']['_staging_field']);
        $this->assertSame('ali', $state['data']['user_name']);
        $this->assertStringContainsString(__('reports.user_info_review.title'), $messages[0] ?? '');
    }

    public function test_report_flow_back_from_staging_returns_to_the_type_and_drops_user_info(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'ali');
        $messages = [];

        $this->handleCallback(CallbackAction::FlowBack->value);

        $state = $conversation->get(self::USER_ID);
        $this->assertSame(0, $state['step']);
        $this->assertArrayNotHasKey('user_name', $state['data']);
        $this->assertSame([__('reports.pick_type')], $messages);
    }

    public function test_report_flow_back_on_the_first_step_cancels_and_restores_the_main_menu(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage');
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text, array $extra): bool => $text === FlowMessageResource::flowCancelled()
                && str_contains($extra['reply_markup'] ?? '', '"keyboard"'));

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackAction::FlowBack->value);

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_report_flow_steps_show_back_and_cancel_on_the_reply_keyboard(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $keyboards = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text, array $extra) use (&$keyboards): void {
                $keyboards[] = array_column(array_merge(...self::replyRows($extra, self::USER_ID)), 'callback_data');
            });

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'admin_panel_problem');

        // Type picker: cancel only. Description prompt: back + cancel.
        $this->assertSame(CallbackAction::FlowCancel->value, end($keyboards[0]));
        $this->assertNotContains(CallbackAction::FlowBack->value, $keyboards[0]);
        $this->assertSame([CallbackAction::FlowBack->value, CallbackAction::FlowCancel->value], $keyboards[1]);
    }

    public function test_report_flow_no_longer_offers_severity_impact_or_assignee_steps(): void
    {
        $this->allowAnsweringCallbackQuery();

        // Type picker + description prompt.
        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage')->times(2);

        // Tracked prompts are cleaned up when the flow executes.
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('deleteMessages')->andReturnTrue();
        Telegram::swap($api);

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $finalized = false;

        $reports = $this->mock(ReportFlowService::class);
        $reports->shouldReceive('finalizeReport')
            ->once()
            ->withArgs(function (int $chatId, array $data, int $userId) use (&$finalized): bool {
                $finalized = ($data['project'] ?? '') === 'TS'
                    && ($data['type'] ?? '') === 'admin_panel_problem'
                    && ($data['description'] ?? '') === 'A complete problem description'
                    && ! isset($data['severity'], $data['impact'], $data['assignee']);

                return true;
            });

        $conversation = app(ConversationManager::class);

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        // Type picker (project is skipped — default is TS).
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'admin_panel_problem');

        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'A complete problem description');

        // The optional pickers remain removed, so the flow executes right after the description.
        $this->assertFalse($conversation->isActive(self::USER_ID));
        $this->assertTrue($finalized, 'The flow must execute with severity/impact/assignee omitted.');
    }

    public function test_user_bug_report_collects_fields_in_any_order_then_confirms(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        $finalized = false;

        $reports = $this->mock(ReportFlowService::class);
        $reports->shouldReceive('finalizeReport')
            ->once()
            ->withArgs(function (int $chatId, array $data, int $userId) use (&$finalized): bool {
                $finalized = ($data['type'] ?? '') === 'user_bug'
                    && ($data['user_name'] ?? '') === 'ali'
                    && ($data['user_identifier'] ?? '') === 'ID-123'
                    && ($data['user_mobile'] ?? '') === '0912'
                    && ! isset($data['usertype'], $data['user_ref'])
                    && ! isset($data['severity'], $data['impact'], $data['assignee']);

                return true;
            });

        $conversation = app(ConversationManager::class);

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');

        // Fill fields in any order, returning to the staging screen each time.
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_mobile');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, '0912');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 44, 'ali');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_identifier');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 45, 'ID-123');

        // Confirm moves to the description step (index 3, category skipped without _cat_ok).
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'confirm');
        $this->assertSame(3, $conversation->get(self::USER_ID)['step']);

        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 46, 'A complete problem description');

        $this->assertFalse($conversation->isActive(self::USER_ID));
        $this->assertTrue($finalized, 'The user-bug flow must execute after the staging fields, confirm, and description.');
    }

    public function test_report_flow_requires_at_least_one_user_field(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        $this->mock(ReportFlowService::class)->shouldNotReceive('finalizeReport');

        $conversation = app(ConversationManager::class);

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'confirm');

        // Still waiting on the staging step with an error re-prompt.
        $this->assertSame(1, $conversation->get(self::USER_ID)['step']);
        $this->assertTrue(
            collect($messages)->contains(fn (string $text): bool => str_contains($text, __('reports.user_info_required')))
        );
        $this->assertTrue($conversation->isActive(self::USER_ID));
    }

    public function test_report_flow_retyping_a_field_updates_it_on_staging(): void
    {
        $this->allowAnsweringCallbackQuery();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $messages = [];
        $this->mockFlowMessenger()
            ->shouldReceive('sendTrackedMessage')
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        $finalized = false;

        $reports = $this->mock(ReportFlowService::class);
        $reports->shouldReceive('finalizeReport')
            ->once()
            ->withArgs(function (int $chatId, array $data, int $userId) use (&$finalized): bool {
                $finalized = ($data['user_name'] ?? '') === 'new-name'
                    && ($data['user_identifier'] ?? '') === 'ID-123'
                    && ($data['user_mobile'] ?? '') === '0912';

                return true;
            });

        $conversation = app(ConversationManager::class);

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'old-name');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_identifier');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 44, 'ID-123');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_mobile');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 45, '0912');

        // Re-click the already-filled username and provide a new value.
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 46, 'new-name');

        // Still on the staging step, updated value preserved alongside the others.
        $this->assertSame(1, $conversation->get(self::USER_ID)['step']);
        $this->assertSame('new-name', $conversation->get(self::USER_ID)['data']['user_name'] ?? null);

        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'confirm');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 47, 'A complete problem description');

        $this->assertFalse($conversation->isActive(self::USER_ID));
        $this->assertTrue($finalized, 'The retyped value must reach finalizeReport.');
    }

    public function test_report_flow_reprompts_when_description_is_too_short(): void
    {
        $this->allowAnsweringCallbackQuery();

        // Type picker + description prompt + short-hint re-prompt.
        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage')->times(3);

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $finalized = false;

        $reports = $this->mock(ReportFlowService::class);
        $reports->shouldReceive('finalizeReport')
            ->once()
            ->withArgs(function (int $chatId, array $data, int $userId) use (&$finalized): bool {
                $finalized = ($data['description'] ?? '') === 'A complete problem description';

                return true;
            });

        $conversation = app(ConversationManager::class);

        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, []);

        // Type picker (project is skipped — default is TS).
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'admin_panel_problem');

        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'short');

        // Still waiting on the description step (index 3).
        $this->assertSame(3, $conversation->get(self::USER_ID)['step']);

        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 44, 'A complete problem description');

        // The flow finishes right after a proper description.
        $this->assertFalse($conversation->isActive(self::USER_ID));
        $this->assertTrue($finalized, 'The flow must wait for a proper description, then continue.');
    }

    public function test_report_flow_handles_category_step_when_available(): void
    {
        $this->allowAnsweringCallbackQuery();

        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturn('customfield_10090');
        $jira->shouldReceive('getCreateFieldAllowedOptions')->with('TS', 'customfield_10090', 'Bug')->andReturn([
            ['id' => '101', 'value' => 'Payment'],
        ]);

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage')->byDefault();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $finalized = false;
        $reports = $this->mock(ReportFlowService::class);
        $reports->shouldReceive('finalizeReport')
            ->once()
            ->withArgs(function (int $chatId, array $data, int $userId) use (&$finalized): bool {
                $finalized = ($data['category'] ?? '') === '101'
                    && ($data['description'] ?? '') === 'A complete problem description';

                return true;
            });

        $conversation = app(ConversationManager::class);

        // Start with _cat_ok => true
        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, ['_cat_ok' => true]);

        // Step 0: type
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'user_bug');

        // Step 1: user info staging
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'user_name');
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 43, 'ali');
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'user_info_staging').'confirm');

        // Step 2: category (only shown for user_bug when _cat_ok)
        $this->assertSame(2, $conversation->get(self::USER_ID)['step']);
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'category').'101');

        // Step 3: description
        $this->assertSame(3, $conversation->get(self::USER_ID)['step']);
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 44, 'A complete problem description');

        $this->assertFalse($conversation->isActive(self::USER_ID));
        $this->assertTrue($finalized, 'Category selection must be passed to finalizeReport.');
    }

    public function test_report_flow_skips_category_step_for_non_bug_reports(): void
    {
        $this->allowAnsweringCallbackQuery();

        $messenger = $this->mockFlowMessenger();
        $messenger->shouldReceive('sendTrackedMessage')->twice();

        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $finalized = false;
        $reports = $this->mock(ReportFlowService::class);
        $reports->shouldReceive('finalizeReport')
            ->once()
            ->withArgs(function (int $chatId, array $data, int $userId) use (&$finalized): bool {
                $finalized = ! isset($data['category'])
                    && ($data['description'] ?? '') === 'A complete problem description';

                return true;
            });

        $conversation = app(ConversationManager::class);

        // Start with _cat_ok => true
        app(FlowService::class)->startFlow(self::USER_ID, self::CHAT_ID, 42, FlowCommand::Report, ['_cat_ok' => true]);

        // Step 0: type = admin_panel_problem
        $this->handleCallback(CallbackData::flowStepPrefix('report', 'type').'admin_panel_problem');

        // Both step 1 (user_info_staging) and step 2 (category) skipped for non-bug
        $this->assertSame(3, $conversation->get(self::USER_ID)['step']);
        app(FlowService::class)->handleFlowText(self::USER_ID, self::CHAT_ID, 44, 'A complete problem description');

        $this->assertFalse($conversation->isActive(self::USER_ID));
        $this->assertTrue($finalized, 'Category step must be skipped for non-bug reports.');
    }

    private function handleCallback(string $data): void
    {
        app(FlowService::class)->handleFlowCallback($this->callbackQuery($data), self::USER_ID);
    }

    private function startCreateConversationAtPriorityStep(?TaskPriority $priority = null): void
    {
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->advance(self::USER_ID, 3, array_filter([
            'project' => 'SCRUM',
            'summary' => 'Fix login bug',
            'description' => 'Detailed description of the problem',
            'priority' => $priority?->value,
        ], fn ($value) => $value !== null));
    }

    private function callbackQuery(string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id' => 'cbq-1',
            'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
            'message' => [
                'message_id' => 42,
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
            ],
            'data' => $data,
        ]);
    }

    private function allowAnsweringCallbackQuery(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        Telegram::swap($api);
    }

    private function mockFlowMessenger(): MockInterface
    {
        return $this->mock(TelegramMessengerInterface::class)
            ->shouldReceive('answerCallback')->byDefault()
            ->getMock();
    }

    private function mockTaskProjectPicker(string $expectedPrefix): void
    {
        $this->mock(TaskService::class)
            ->shouldReceive('showProjectPicker')
            ->once()
            ->withArgs(function (int $chatId, string $prefix) use ($expectedPrefix): bool {
                return $chatId === self::CHAT_ID && $prefix === $expectedPrefix;
            });
    }

    private function mockJiraTasks(array $result): void
    {
        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('getIssues')
            ->andReturn($result);
    }
}
