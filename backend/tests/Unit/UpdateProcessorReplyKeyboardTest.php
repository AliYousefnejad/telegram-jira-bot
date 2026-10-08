<?php

namespace Tests\Unit;

use App\Enums\FlowCommand;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\BotInfoService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\Reports\TaskManagementService;
use App\Services\Telegram\TopicAccessService;
use App\Services\Telegram\UpdateProcessor;
use Telegram\Bot\Objects\Update;
use Tests\Concerns\FakesJiraUserLookup;
use Tests\TestCase;

class UpdateProcessorReplyKeyboardTest extends TestCase
{
    use FakesJiraUserLookup;

    private const CHAT_ID = -100123456789;

    private const USER_ID = 111;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.admin_ids' => [self::USER_ID],
            'services.telegram.allowed_group_ids' => [self::CHAT_ID],
        ]);

        $this->fakeJiraUserLookup();
    }

    public function test_reply_keyboard_manage_tasks_button_in_group_opens_project_picker(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->with(self::USER_ID)->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->with(self::USER_ID)->andReturnTrue();

        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();

        $reportsTasks = $this->mock(TaskManagementService::class);
        $reportsTasks->shouldReceive('consumePendingText')->andReturnFalse();
        $reportsTasks->shouldReceive('openProjectPicker')
            ->once()
            ->withArgs(function (int $chatId, int $userId, int $messageId, bool $isGroup, ?int $threadId): bool {
                return $chatId === self::CHAT_ID
                    && $userId === self::USER_ID
                    && $isGroup === true;
            });

        $update = $this->buildMessageUpdate(
            (string) __('keyboard.button.manage_tasks', [], 'fa'),
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);
    }

    public function test_reply_keyboard_report_button_in_group_starts_report_flow(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->with(self::USER_ID)->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->with(self::USER_ID)->andReturnTrue();

        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();

        $this->mock(TaskManagementService::class)->shouldReceive('consumePendingText')->andReturnFalse();

        $flow = $this->mock(FlowService::class);
        $flow->shouldReceive('startFlow')
            ->once()
            ->withArgs(function (int $userId, int $chatId, int $messageId, FlowCommand $command, $data, bool $isGroup): bool {
                return $userId === self::USER_ID
                    && $chatId === self::CHAT_ID
                    && $command === FlowCommand::Report
                    && $isGroup === true;
            });

        $update = $this->buildMessageUpdate(
            (string) __('keyboard.button.register_report', [], 'fa'),
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);
    }

    public function test_reply_keyboard_timeline_button_in_group_opens_timeline_menu(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->with(self::USER_ID)->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->with(self::USER_ID)->andReturnTrue();

        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();

        $reportsTasks = $this->mock(TaskManagementService::class);
        $reportsTasks->shouldReceive('consumePendingText')->andReturnFalse();
        $reportsTasks->shouldReceive('openTimelineMenu')
            ->once()
            ->withArgs(function (int $chatId, int $userId, int $messageId, bool $isGroup, ?int $threadId): bool {
                return $chatId === self::CHAT_ID
                    && $userId === self::USER_ID
                    && $isGroup === true;
            });

        $update = $this->buildMessageUpdate(
            (string) __('keyboard.button.timeline', [], 'fa'),
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);
    }

    public function test_reply_keyboard_help_button_in_group_shows_help(): void
    {
        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();
        $this->mock(TaskManagementService::class)->shouldReceive('consumePendingText')->andReturnFalse();

        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')
            ->once()
            ->withArgs(function (int $chatId, bool $isGroup, int $messageId, ?int $threadId, ?int $userId): bool {
                return $chatId === self::CHAT_ID
                    && $isGroup === true
                    && $userId === self::USER_ID;
            });

        $update = $this->buildMessageUpdate(
            (string) __('keyboard.button.help', [], 'fa'),
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);
    }

    public function test_reply_keyboard_link_button_in_group_starts_link_flow(): void
    {
        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();
        $this->mock(TaskManagementService::class)->shouldReceive('consumePendingText')->andReturnFalse();

        $flow = $this->mock(FlowService::class);
        $flow->shouldReceive('startFlow')
            ->once()
            ->withArgs(function (int $userId, int $chatId, int $messageId, FlowCommand $command, $data, bool $isGroup): bool {
                return $userId === self::USER_ID
                    && $chatId === self::CHAT_ID
                    && $command === FlowCommand::Link
                    && $isGroup === true;
            });

        $update = $this->buildMessageUpdate(
            (string) __('keyboard.button.link_account', [], 'fa'),
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);
    }

    public function test_random_text_message_in_group_is_silently_ignored(): void
    {
        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();

        $flow = $this->mock(FlowService::class);
        $flow->shouldNotReceive('startFlow');

        $reportsTasks = $this->mock(TaskManagementService::class);
        $reportsTasks->shouldReceive('consumePendingText')->andReturnFalse();
        $reportsTasks->shouldNotReceive('openProjectPicker');
        $reportsTasks->shouldNotReceive('openTimelineMenu');

        $info = $this->mock(BotInfoService::class);
        $info->shouldNotReceive('help');
        $info->shouldNotReceive('start');

        $update = $this->buildMessageUpdate(
            'سلام، این یک پیام معمولی در گروه است',
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);
    }

    public function test_reply_keyboard_button_clears_active_conversation_before_dispatching(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->with(self::USER_ID)->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->with(self::USER_ID)->andReturnTrue();

        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Report, self::USER_ID, self::CHAT_ID, 10, true);
        $this->assertTrue($conversation->isActive(self::USER_ID));

        $reportsTasks = $this->mock(TaskManagementService::class);
        $reportsTasks->shouldReceive('consumePendingText')->andReturnFalse();
        $reportsTasks->shouldReceive('openProjectPicker')->once();

        $update = $this->buildMessageUpdate(
            (string) __('keyboard.button.manage_tasks', [], 'fa'),
            self::CHAT_ID,
            self::USER_ID,
            'supergroup'
        );

        app(UpdateProcessor::class)->process($update);

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    private function buildMessageUpdate(string $text, int $chatId, int $userId, string $chatType): Update
    {
        return new Update([
            'update_id' => 99999,
            'message' => [
                'message_id' => 42,
                'text' => $text,
                'chat' => [
                    'id' => $chatId,
                    'type' => $chatType,
                ],
                'from' => [
                    'id' => $userId,
                    'first_name' => 'Ali',
                    'username' => 'ali_dev',
                ],
            ],
        ]);
    }
}
