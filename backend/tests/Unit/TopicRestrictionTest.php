<?php

namespace Tests\Unit;

use App\Contracts\TelegramMessengerInterface;
use App\Models\AllowedTopic;
use App\Models\JiraUser;
use App\Models\TelegramGroup;
use App\Services\Telegram\UpdateProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\ChatMember;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class TopicRestrictionTest extends TestCase
{
    use DatabaseTransactions;

    private const GROUP_CHAT_ID = -1001234567890;

    private const USER_ID = 777;

    private const ALLOWED_THREAD = 42;

    private const BLOCKED_THREAD = 99;

    private const MESSAGE_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $fake = new TopicRestrictionChatMemberFake;
        $this->app->instance(BotsManager::class, $fake);
        $this->app->instance('telegram', $fake);
    }

    private function setUpGroupWithRestriction(): void
    {
        config(['services.telegram.admin_ids' => [self::USER_ID]]);

        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);
        $group->allowedTopics()->create(['message_thread_id' => self::ALLOWED_THREAD, 'topic_name' => 'Bug Reports']);
    }

    public function test_unrestricted_group_allows_all_topics(): void
    {
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('send')->with(
            self::GROUP_CHAT_ID,
            Mockery::type('string'),
            true,
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === self::ALLOWED_THREAD),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/start',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));
    }

    public function test_restricted_topic_allows_permitted_topic(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('send')->with(
            self::GROUP_CHAT_ID,
            Mockery::type('string'),
            true,
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === self::ALLOWED_THREAD),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 2,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/start',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));
    }

    public function test_restricted_topic_silently_ignores_blocked_topic(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 3,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/start',
                'message_thread_id' => self::BLOCKED_THREAD,
            ],
        ]));
    }

    public function test_restricted_topic_silently_ignores_general_topic(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 4,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/start',
            ],
        ]));
    }

    public function test_callback_query_from_blocked_topic_is_silently_ignored(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('answerCallback');
        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 5,
            'callback_query' => new CallbackQuery([
                'id' => 'cbq-test',
                'from' => ['id' => self::USER_ID],
                'message' => [
                    'message_id' => 200,
                    'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                    'message_thread_id' => self::BLOCKED_THREAD,
                ],
                'data' => 'flow_about',
            ]),
        ]));
    }

    public function test_admin_commands_bypass_topic_restriction(): void
    {
        $this->setUpGroupWithRestriction();

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('replyToMessage')->with(
            self::GROUP_CHAT_ID,
            Mockery::type('string'),
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === self::BLOCKED_THREAD),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 6,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/list_allowed_topics',
                'message_thread_id' => self::BLOCKED_THREAD,
            ],
        ]));
    }

    public function test_allow_topic_from_non_allowed_topic_bypasses_restriction(): void
    {
        $this->setUpGroupWithRestriction();

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('replyToMessage')->with(
            self::GROUP_CHAT_ID,
            Mockery::type('string'),
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === self::BLOCKED_THREAD),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 7,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/allow_topic',
                'message_thread_id' => self::BLOCKED_THREAD,
            ],
        ]));
    }

    public function test_private_chat_not_affected_by_topic_restriction(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('send')->with(
            555,
            Mockery::type('string'),
            false,
            self::MESSAGE_ID,
            Mockery::on(fn (array $extra) => ($extra['message_thread_id'] ?? null) === null),
        )->atLeast()->once();

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 8,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Test'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => '/start',
            ],
        ]));
    }

    public function test_forum_topic_created_updates_allowed_topic_name(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        // Service messages are silently consumed — no user-facing response expected.
        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 100,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'message_thread_id' => self::ALLOWED_THREAD,
                'forum_topic_created' => [
                    'name' => 'Backend Bugs',
                    'icon_color' => 7322096,
                ],
            ],
        ]));

        $topic = AllowedTopic::where('message_thread_id', self::ALLOWED_THREAD)->first();
        $this->assertNotNull($topic);
        $this->assertSame('Backend Bugs', $topic->topic_name);
    }

    public function test_forum_topic_edited_updates_topic_name(): void
    {
        $this->setUpGroupWithRestriction();

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 101,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'message_thread_id' => self::ALLOWED_THREAD,
                'forum_topic_edited' => [
                    'name' => 'Renamed Topic',
                ],
            ],
        ]));

        $topic = AllowedTopic::where('message_thread_id', self::ALLOWED_THREAD)->first();
        $this->assertNotNull($topic);
        $this->assertSame('Renamed Topic', $topic->topic_name);
    }

    public function test_forum_topic_created_for_non_allowed_topic_stores_name(): void
    {
        config(['services.telegram.admin_ids' => [self::USER_ID]]);

        $group = TelegramGroup::create(['chat_id' => self::GROUP_CHAT_ID, 'is_forum' => true]);

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 102,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'message_thread_id' => 555,
                'forum_topic_created' => [
                    'name' => 'New Topic',
                    'icon_color' => 7322096,
                ],
            ],
        ]));

        $topic = AllowedTopic::where('message_thread_id', 555)->first();
        $this->assertNotNull($topic);
        $this->assertSame('New Topic', $topic->topic_name);
    }

    public function test_list_allowed_topics_shows_name_unknown_for_null_names(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        // Overwrite topic_name to null to simulate the old bug.
        AllowedTopic::where('message_thread_id', self::ALLOWED_THREAD)
            ->update(['topic_name' => null]);

        // The user must be linked for /list_allowed_topics to be processed.
        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $sentText = null;
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('replyToMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text) use (&$sentText): void {
                $sentText = $text;
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 103,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/list_allowed_topics',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $this->assertNotNull($sentText);
        $this->assertStringContainsString('(name unknown)', $sentText);
        $this->assertStringContainsString((string) self::ALLOWED_THREAD, $sentText);
    }

    public function test_set_topic_name_updates_name(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $sentTexts = [];
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('sendTrackedMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$sentTexts): object {
                $sentTexts[] = $text;

                return new Message(['message_id' => 999]);
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 200,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/set_topic_name',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $this->assertNotEmpty($sentTexts);

        $messenger->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text) use (&$sentTexts): object {
                $sentTexts[] = $text;

                return new Message(['message_id' => 998]);
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 201,
            'message' => [
                'message_id' => 998,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => 'My New Name',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $topic = AllowedTopic::where('message_thread_id', self::ALLOWED_THREAD)->first();
        $this->assertSame('My New Name', $topic->topic_name);
    }

    public function test_set_topic_name_works_when_topic_name_is_null(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        AllowedTopic::where('message_thread_id', self::ALLOWED_THREAD)
            ->update(['topic_name' => null]);

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $sentTexts = [];
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('sendTrackedMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$sentTexts): object {
                $sentTexts[] = $text;

                return new Message(['message_id' => 997]);
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 210,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/set_topic_name',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $messenger->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text) use (&$sentTexts): object {
                $sentTexts[] = $text;

                return new Message(['message_id' => 996]);
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 211,
            'message' => [
                'message_id' => 996,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => 'Fixed Name',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $topic = AllowedTopic::where('message_thread_id', self::ALLOWED_THREAD)->first();
        $this->assertSame('Fixed Name', $topic->topic_name);
    }

    public function test_set_topic_name_empty_args_starts_flow(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $sentText = null;
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('sendTrackedMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, int $userId, string $text) use (&$sentText): object {
                $sentText = $text;

                return new Message(['message_id' => 995]);
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 201,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/set_topic_name',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $this->assertNotNull($sentText);
        $this->assertStringContainsString('topic name', $sentText);
    }

    public function test_set_topic_name_non_admin_rejected(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'User',
            'jira_account_id' => 'user',
            'telegram_admin_id' => 999,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, 999, 'member');

        $sentText = null;
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('replyToMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text) use (&$sentText): void {
                $sentText = $text;
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 202,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => 999, 'first_name' => 'User'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/set_topic_name My New Name',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $this->assertNotNull($sentText);
        $this->assertStringContainsString('Only group admins', $sentText);
    }

    public function test_set_topic_name_not_in_allowed_list_rejected(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $sentText = null;
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('replyToMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text) use (&$sentText): void {
                $sentText = $text;
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 203,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/set_topic_name My New Name',
                'message_thread_id' => 999,
            ],
        ]));

        $this->assertNotNull($sentText);
        $this->assertStringContainsString('not in the allowed list', $sentText);
    }

    public function test_set_topic_name_outside_topic_rejected(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $sentText = null;
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('replyToMessage')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text) use (&$sentText): void {
                $sentText = $text;
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 204,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/set_topic_name My New Name',
            ],
        ]));

        $this->assertNotNull($sentText);
        $this->assertStringContainsString('forum', $sentText);
    }

    public function test_command_reply_carries_thread_id(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $capturedExtra = null;
        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (int $chatId, string $text, bool $isGroup, int $messageId, array $extra) use (&$capturedExtra): void {
                $capturedExtra = $extra;
            });

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 300,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/help',
                'message_thread_id' => self::ALLOWED_THREAD,
            ],
        ]));

        $this->assertNotNull($capturedExtra);
        $this->assertSame(self::ALLOWED_THREAD, $capturedExtra['message_thread_id']);
    }

    public function test_general_topic_reply_has_no_thread_id(): void
    {
        // When topic restrictions are active, the General topic (null thread ID)
        // is NOT allowed, so the bot should not reply at all.
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('send');
        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 301,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/help',
            ],
        ]));
    }

    public function test_blocked_topic_sends_no_reply(): void
    {
        $this->setUpGroupWithRestriction();
        app()->setLocale('en');

        JiraUser::create([
            'display_name' => 'Admin',
            'jira_account_id' => 'admin',
            'telegram_admin_id' => self::USER_ID,
        ]);

        $telegram = $this->app->make(BotsManager::class);
        $telegram->setChatMember(self::GROUP_CHAT_ID, self::USER_ID, 'administrator');

        $messenger = Mockery::mock(TelegramMessengerInterface::class)->shouldIgnoreMissing();
        $this->app->instance(TelegramMessengerInterface::class, $messenger);

        $messenger->shouldNotReceive('replyToMessage');
        $messenger->shouldNotReceive('sendMessage');
        $messenger->shouldNotReceive('send');

        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 302,
            'message' => [
                'message_id' => self::MESSAGE_ID,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Admin'],
                'chat' => ['id' => self::GROUP_CHAT_ID, 'type' => 'supergroup'],
                'text' => '/help',
                'message_thread_id' => self::BLOCKED_THREAD,
            ],
        ]));
    }
}

class TopicRestrictionChatMemberFake
{
    /** @var array<int, array<int, string>> */
    private array $chatMembers = [];

    public bool $shouldThrowOnGetChatMember = false;

    /**
     * @param  array<string, mixed>  $params
     */
    public function getChatMember(array $params): ChatMember
    {
        if ($this->shouldThrowOnGetChatMember) {
            throw new \RuntimeException('bot is not a chat member');
        }

        $chatId = $params['chat_id'];
        $userId = $params['user_id'];
        $status = $this->chatMembers[$chatId][$userId] ?? 'member';

        return new ChatMember([
            'user' => ['id' => $userId],
            'status' => $status,
        ]);
    }

    public function setChatMember(int $chatId, int $userId, string $status): void
    {
        $this->chatMembers[$chatId][$userId] = $status;
    }
}
