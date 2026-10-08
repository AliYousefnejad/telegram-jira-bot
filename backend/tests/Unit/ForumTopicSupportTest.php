<?php

namespace Tests\Unit;

use App\Contracts\TelegramMessengerInterface;
use App\DTOs\ConversationState;
use App\Services\Telegram\UpdateProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class ForumTopicSupportTest extends TestCase
{
    use DatabaseTransactions;

    private const CHAT_ID = 555;

    private const USER_ID = 777;

    private const THREAD_ID = 42;

    private const MESSAGE_ID = 100;

    public function test_extract_message_thread_id_from_message_update(): void
    {
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('send')->with(
            self::CHAT_ID,
            Mockery::type('string'),
            false,
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === self::THREAD_ID),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'text' => '/start',
                'message_thread_id' => self::THREAD_ID,
            ],
        ]));
    }

    public function test_private_chat_message_has_no_thread_id(): void
    {
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('send')->with(
            self::CHAT_ID,
            Mockery::type('string'),
            false,
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === null),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 2,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'text' => '/start',
            ],
        ]));
    }

    public function test_callback_query_extracts_thread_id(): void
    {
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('answerCallback')->once();

        $messenger->shouldReceive('send')->with(
            self::CHAT_ID,
            Mockery::type('string'),
            false,
            0,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === self::THREAD_ID),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 3,
            'callback_query' => new CallbackQuery([
                'id' => 'cbq-test',
                'from' => ['id' => self::USER_ID],
                'message' => [
                    'message_id' => 200,
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                    'message_thread_id' => self::THREAD_ID,
                ],
                'data' => 'flow_help',
            ]),
        ]));
    }

    public function test_conversation_state_serializes_message_thread_id(): void
    {
        $state = new ConversationState(
            command: 'link',
            step: 0,
            data: [],
            chatId: self::CHAT_ID,
            messageId: self::MESSAGE_ID,
            isGroup: false,
            messageThreadId: self::THREAD_ID,
        );

        $array = $state->toArray();

        $this->assertSame(self::THREAD_ID, $array['message_thread_id']);

        $restored = ConversationState::fromArray($array);

        $this->assertSame(self::THREAD_ID, $restored->messageThreadId);
    }

    public function test_conversation_state_null_thread_id_serializes_correctly(): void
    {
        $state = new ConversationState(
            command: 'link',
            step: 0,
            data: [],
            chatId: self::CHAT_ID,
            messageId: self::MESSAGE_ID,
            isGroup: false,
        );

        $array = $state->toArray();

        $this->assertNull($array['message_thread_id']);

        $restored = ConversationState::fromArray($array);

        $this->assertNull($restored->messageThreadId);
    }
}
