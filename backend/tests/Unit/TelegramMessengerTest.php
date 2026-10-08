<?php

namespace Tests\Unit;

use App\Enums\FlowCommand;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\ReplyKeyboardService;
use App\Services\Telegram\TelegramMessenger;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class TelegramMessengerTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_connection_failure_is_retried_once_and_the_message_is_delivered(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(function (): object {
                static $attempts = 0;

                if (++$attempts === 1) {
                    throw $this->connectionFailure();
                }

                return new Message(['message_id' => 77]);
            });
        Telegram::swap($api);

        Log::shouldReceive('warning')->once();

        $message = app(TelegramMessenger::class)->sendMessage(self::CHAT_ID, 'hello');

        $this->assertSame(77, $message->message_id);
    }

    public function test_reply_keyboard_taps_are_not_answered_as_callback_queries(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->once()->with(['callback_query_id' => 'real-1']);
        Telegram::swap($api);

        $messenger = app(TelegramMessenger::class);
        $messenger->answerCallback(new CallbackQuery(['id' => ReplyKeyboardService::VIRTUAL_CALLBACK_ID, 'data' => 'rptexit']));
        $messenger->answerCallback(new CallbackQuery(['id' => 'real-1', 'data' => 'rptexit']));

        // tearDown closes Mockery itself, so count the verified expectation here.
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_api_errors_are_never_retried(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new TelegramSDKException('Bad Request: chat not found'));
        Telegram::swap($api);

        Log::shouldReceive('warning')->never();
        Log::shouldReceive('error')->never();

        $this->expectException(TelegramSDKException::class);

        app(TelegramMessenger::class)->sendMessage(self::CHAT_ID, 'hello');
    }

    public function test_a_persistent_connection_failure_fails_after_the_single_retry(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->twice()
            ->andThrow($this->connectionFailure());
        Telegram::swap($api);

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->once();

        $this->expectException(TelegramSDKException::class);

        app(TelegramMessenger::class)->sendMessage(self::CHAT_ID, 'hello');
    }

    public function test_tracked_messages_record_the_delivered_message_id(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Message(['message_id' => 88]));
        Telegram::swap($api);

        // Tracked messages live inside the active conversation state.
        $conversation = app(ConversationManager::class);
        $conversation->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);

        app(TelegramMessenger::class)->sendTrackedMessage(self::CHAT_ID, self::USER_ID, 'hello');

        $this->assertSame([88], $conversation->getTrackedMessages(self::USER_ID));
    }

    private function connectionFailure(): TelegramSDKException
    {
        return new TelegramSDKException(
            'cURL error 28: SSL connection timeout',
            0,
            new ConnectException('cURL error 28: SSL connection timeout', new Request('POST', 'https://api.telegram.org'))
        );
    }
}
