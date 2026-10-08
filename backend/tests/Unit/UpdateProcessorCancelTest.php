<?php

namespace Tests\Unit;

use App\Enums\FlowCommand;
use App\Resources\FlowMessageResource;
use App\Services\Jira\JiraService;
use App\Services\Jira\SyncService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\BotInfoService;
use App\Services\Telegram\CallbackService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\TelegramMessenger;
use App\Services\Telegram\UpdateProcessor;
use Mockery;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class UpdateProcessorCancelTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);
        Mockery::close();

        parent::tearDown();
    }

    public function test_typing_cancel_deletes_flow_messages_and_clears_the_conversation(): void
    {
        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->addTrackedMessage(self::USER_ID, 101);
        $conversation->addTrackedMessage(self::USER_ID, 102);

        $this->mock(TelegramMessenger::class)
            ->shouldReceive('deleteMessagesQuietly')
            ->once()
            ->with(self::CHAT_ID, [101, 102])
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::flowCancelled(), Mockery::type('array'));

        // The other collaborators must stay untouched.
        $this->mock(FlowService::class)->shouldNotReceive('handleFlowText');
        $this->mock(CallbackService::class)->shouldNotReceive('handleCallbackQuery');

        app(UpdateProcessor::class)->process($this->message('/cancel'));

        $this->assertFalse($conversation->isActive(self::USER_ID));
    }

    public function test_a_non_flow_command_while_a_flow_is_active_leaves_the_conversation_intact(): void
    {
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnTrue();

        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);
        $conversation->addTrackedMessage(self::USER_ID, 101);

        $this->mock(BotInfoService::class)->shouldReceive('help')->once();
        $this->mock(JiraService::class);
        $this->mock(SyncService::class);

        // Info commands never touch a running conversation: the tracked
        // messages stay and the flow keeps waiting for its next input.
        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldNotReceive('deleteMessagesQuietly');
        $messenger->shouldNotReceive('sendMessage');

        app(UpdateProcessor::class)->process($this->message('/help'));

        $this->assertTrue($conversation->isActive(self::USER_ID));
    }

    private function message(string $text): Update
    {
        return new Update([
            'update_id' => 2,
            'message' => [
                'message_id' => 20,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'text' => $text,
            ],
        ]);
    }
}
