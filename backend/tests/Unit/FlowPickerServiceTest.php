<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Services\Jira\TaskService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowPickerService;
use App\Services\Telegram\ReplyKeyboardService;
use Tests\TestCase;

class FlowPickerServiceTest extends TestCase
{
    private int $userId = 12345;

    private int $chatId = 99999;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear($this->userId);

        parent::tearDown();
    }

    public function test_show_flow_picker_sends_invalid_input_for_unknown_picker(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->with($this->chatId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'unknown_picker', 'report', [], $this->userId);
    }

    public function test_show_flow_picker_priority_sends_tracked_message(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendTrackedMessage')
            ->with($this->chatId, $this->userId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'priority', 'report', [], $this->userId);
    }

    public function test_show_flow_picker_report_type_sends_tracked_message(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendTrackedMessage')
            ->with($this->chatId, $this->userId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'report_type', 'report', [], $this->userId);
    }

    public function test_show_flow_picker_user_info_staging_sends_tracked_message(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendTrackedMessage')
            ->with($this->chatId, $this->userId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'user_info_staging', 'report', [
            'user_name' => 'ali',
        ], $this->userId);
    }

    public function test_show_flow_picker_severity_sends_tracked_message(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendTrackedMessage')
            ->with($this->chatId, $this->userId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'severity', 'report', [], $this->userId);
    }

    public function test_show_flow_picker_impact_sends_tracked_message(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendTrackedMessage')
            ->with($this->chatId, $this->userId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'impact', 'report', [], $this->userId);
    }

    public function test_show_flow_picker_assignee_with_empty_project_does_nothing(): void
    {
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('sendTrackedMessage');

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'assignee', 'report', ['project' => ''], $this->userId);
    }

    public function test_show_flow_picker_assignee_sends_no_users_when_empty(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getAssignableUsersForProject')
            ->with('TEST')
            ->andReturn([]);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->with($this->chatId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'assignee', 'report', ['project' => 'TEST'], $this->userId);
    }

    public function test_show_flow_picker_user_sends_no_users_when_task_has_no_assignable_users(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getAssignableUsers')
            ->with('TEST-123')
            ->andReturn([]);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->with($this->chatId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'user', 'report', ['task' => 'TEST-123'], $this->userId);
    }

    public function test_show_flow_picker_board_sends_no_boards_when_empty(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getBoards')
            ->andReturn([]);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->with($this->chatId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->showFlowPicker($this->chatId, 'board', 'report', [], $this->userId);
    }

    public function test_send_sprints_from_flow_sends_empty_when_no_sprints(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getSprints')
            ->with(42)
            ->andReturn([]);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->with($this->chatId, \Mockery::type('string'), \Mockery::type('array'))
            ->once();

        $service = $this->makeService();
        $service->sendSprintsFromFlow($this->chatId, 42);
    }

    private function makeService(): FlowPickerService
    {
        return new FlowPickerService(
            messenger: app(TelegramMessengerInterface::class),
            jira: app(JiraServiceInterface::class),
            tasks: app(TaskService::class),
            access: app(AccessService::class),
            conversation: app(ConversationManager::class),
            replyKeyboard: app(ReplyKeyboardService::class),
        );
    }
}
