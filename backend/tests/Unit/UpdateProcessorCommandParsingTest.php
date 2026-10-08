<?php

namespace Tests\Unit;

use App\Services\Telegram\AccessService;
use App\Services\Telegram\BotInfoService;
use App\Services\Telegram\CallbackService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\Reports\TaskManagementService;
use App\Services\Telegram\TelegramMessenger;
use App\Services\Telegram\UpdateProcessor;
use Mockery;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class UpdateProcessorCommandParsingTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnTrue();
        $this->mock(TelegramMessenger::class)->shouldNotReceive('replyToMessage');
        $this->mock(CallbackService::class)->shouldNotReceive('handleCallbackQuery');
        $this->mock(FlowService::class)->shouldNotReceive('handleFlowText');
        $this->mock(TaskManagementService::class)->shouldReceive('consumePendingText')->andReturnFalse();
    }

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);
        Mockery::close();

        parent::tearDown();
    }

    public function test_plain_start_command_is_handled(): void
    {
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('start')->once();

        app(UpdateProcessor::class)->process($this->message('/start'));
    }

    public function test_start_with_bot_username_suffix_is_handled(): void
    {
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('start')->once();

        app(UpdateProcessor::class)->process($this->message('/start@MyBotUsername'));
    }

    public function test_start_with_lowercase_bot_username_suffix_is_handled(): void
    {
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('start')->once();

        app(UpdateProcessor::class)->process($this->message('/start@mybotusername'));
    }

    public function test_help_with_bot_username_suffix_is_handled(): void
    {
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')->once();

        app(UpdateProcessor::class)->process($this->message('/help@MyBotUsername'));
    }

    public function test_about_with_bot_username_suffix_falls_back_to_help(): void
    {
        // About was removed — unknown commands fall back to help.
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')->once();

        app(UpdateProcessor::class)->process($this->message('/about@SomeBot'));
    }

    public function test_projects_with_bot_username_suffix_falls_back_to_help(): void
    {
        // Projects was removed — unknown commands fall back to help.
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')->once();

        app(UpdateProcessor::class)->process($this->message('/projects@SomeBot'));
    }

    public function test_boards_with_bot_username_suffix_falls_back_to_help(): void
    {
        // Boards was removed — unknown commands fall back to help.
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')->once();

        app(UpdateProcessor::class)->process($this->message('/boards@SomeBot'));
    }

    public function test_command_with_bot_username_in_group_is_handled(): void
    {
        $info = $this->mock(BotInfoService::class);
        $info->shouldReceive('help')->once();

        $processor = app(UpdateProcessor::class);
        $processor->process(new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'text' => '/help@MyBotUsername',
            ],
        ]));
    }

    private function message(string $text): Update
    {
        return new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => random_int(1, PHP_INT_MAX),
                'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'text' => $text,
            ],
        ]);
    }
}
