<?php

namespace Tests\Unit;

use App\Services\Telegram\AccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Objects\ChatMember;
use Tests\TestCase;

class AccessServiceGroupAdminTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_ID = 100000001;

    private const GROUP_CHAT_ID = -1001234567890;

    private RecordingChatMemberFake $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.admin_ids' => [self::ADMIN_ID]]);

        $this->telegram = new RecordingChatMemberFake;
        $this->app->instance(BotsManager::class, $this->telegram);
        $this->app->instance('telegram', $this->telegram);
    }

    public function test_is_group_admin_returns_true_for_admin_status(): void
    {
        $this->telegram->setChatMember(self::GROUP_CHAT_ID, self::ADMIN_ID, 'administrator');

        $access = app(AccessService::class);

        $this->assertTrue($access->isGroupAdmin(self::GROUP_CHAT_ID, self::ADMIN_ID));
    }

    public function test_is_group_admin_returns_true_for_creator_status(): void
    {
        $this->telegram->setChatMember(self::GROUP_CHAT_ID, self::ADMIN_ID, 'creator');

        $access = app(AccessService::class);

        $this->assertTrue($access->isGroupAdmin(self::GROUP_CHAT_ID, self::ADMIN_ID));
    }

    public function test_is_group_admin_returns_false_for_member_status(): void
    {
        $this->telegram->setChatMember(self::GROUP_CHAT_ID, self::ADMIN_ID, 'member');

        $access = app(AccessService::class);

        $this->assertFalse($access->isGroupAdmin(self::GROUP_CHAT_ID, self::ADMIN_ID));
    }

    public function test_is_group_admin_returns_false_for_non_bot_admin(): void
    {
        $access = app(AccessService::class);

        $this->assertFalse($access->isGroupAdmin(self::GROUP_CHAT_ID, 99999));
    }

    public function test_is_group_admin_returns_false_on_api_exception(): void
    {
        $this->telegram->shouldThrowOnGetChatMember = true;

        $access = app(AccessService::class);

        $this->assertFalse($access->isGroupAdmin(self::GROUP_CHAT_ID, self::ADMIN_ID));
    }
}

class RecordingChatMemberFake
{
    /** @var array<string, array{int: string}> */
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
        $status = $this->chatMembers[$chatId][$userId] ?? 'left';

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
