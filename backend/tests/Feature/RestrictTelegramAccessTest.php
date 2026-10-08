<?php

namespace Tests\Feature;

use App\Resources\CommonMessageResource;
use Illuminate\Support\Facades\Config;
use Telegram\Bot\BotsManager;
use Tests\TestCase;

class RestrictTelegramAccessTest extends TestCase
{
    private const ADMIN_ID = 100000001;

    private const MEMBER_ID = 111222333;

    private const ALLOWED_GROUP_ID = -1001234567890;

    private const FOREIGN_GROUP_ID = -1009998887776;

    private RecordingTelegramFake $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        // Webhook disabled (the bot polls) — restore with the route in routes/api.php.
        $this->markTestSkipped('Webhook route disabled.');

        Config::set('services.telegram.admin_ids', [self::ADMIN_ID]);
        Config::set('services.telegram.allowed_group_ids', [self::ALLOWED_GROUP_ID]);

        $this->telegram = new RecordingTelegramFake;
        $this->app->instance(BotsManager::class, $this->telegram);
        $this->app->instance('telegram', $this->telegram);
    }

    public function test_allows_admin_in_private_chat(): void
    {
        $response = $this->postJson('/api/telegram/webhook', $this->message(self::ADMIN_ID, ['id' => self::ADMIN_ID, 'type' => 'private'], '/help'));

        $response->assertOk();
        $sentTexts = $this->sentTexts();
        $this->assertNotContains(CommonMessageResource::notAuthorizedUser(), $sentTexts);
        $this->assertNotContains(CommonMessageResource::notAuthorizedGroup(), $sentTexts);
        $this->assertNotEmpty($sentTexts);
    }

    public function test_rejects_non_admin_in_private_chat(): void
    {
        $response = $this->postJson('/api/telegram/webhook', $this->message(self::MEMBER_ID, ['id' => self::MEMBER_ID, 'type' => 'private'], '/help'));

        $response->assertOk();
        $this->assertSame([CommonMessageResource::notAuthorizedUser()], $this->sentTexts());
    }

    public function test_rejects_admin_in_group_that_is_not_allowed(): void
    {
        $response = $this->postJson('/api/telegram/webhook', $this->message(self::ADMIN_ID, ['id' => self::FOREIGN_GROUP_ID, 'type' => 'supergroup'], '/help'));

        $response->assertOk();
        $this->assertSame([CommonMessageResource::notAuthorizedGroup()], $this->sentTexts());
    }

    public function test_allows_admin_in_allowed_group(): void
    {
        $response = $this->postJson('/api/telegram/webhook', $this->message(self::ADMIN_ID, ['id' => self::ALLOWED_GROUP_ID, 'type' => 'supergroup'], '/help'));

        $response->assertOk();
        $this->assertNotContains(CommonMessageResource::notAuthorizedGroup(), $this->sentTexts());
        $this->assertNotEmpty($this->sentTexts());
    }

    public function test_rejects_callback_from_non_admin_with_alert(): void
    {
        $payload = [
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => self::MEMBER_ID],
                'data' => 'noop',
                'message' => [
                    'message_id' => 20,
                    'chat' => ['id' => self::ALLOWED_GROUP_ID, 'type' => 'supergroup'],
                ],
            ],
        ];

        $response = $this->postJson('/api/telegram/webhook', $payload);

        $response->assertOk();
        $this->assertSame([], $this->telegram->sentMessages);
        $this->assertCount(1, $this->telegram->answeredCallbacks);

        [$callbackQueryId, $payload] = $this->telegram->answeredCallbacks[0];
        $this->assertSame('cb-1', $callbackQueryId);
        $this->assertTrue($payload['show_alert']);
        $this->assertSame(CommonMessageResource::notAuthorizedUser(), $payload['text']);
    }

    /**
     * @return list<string>
     */
    private function sentTexts(): array
    {
        return array_map(fn (array $arguments) => $arguments['text'], $this->telegram->sentMessages);
    }

    /**
     * @param  array{id: int, type: string}  $chat
     * @return array<string, mixed>
     */
    private function message(int $userId, array $chat, string $text): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => $userId],
                'chat' => $chat,
                'text' => $text,
            ],
        ];
    }
}

class RecordingTelegramFake
{
    /** @var list<array<string, mixed>> */
    public array $sentMessages = [];

    /** @var list<array{0: string|int, 1: array<string, mixed>}> */
    public array $answeredCallbacks = [];

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function sendMessage(array $arguments): void
    {
        $this->sentMessages[] = $arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function answerCallbackQuery(array $arguments): void
    {
        $this->answeredCallbacks[] = [$arguments['callback_query_id'], $arguments];
    }
}
