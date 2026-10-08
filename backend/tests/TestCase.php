<?php

namespace Tests;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Services\Jira\JiraService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\ReplyKeyboardService;
use App\Services\Telegram\Reports\TaskManagementService;
use App\Services\Telegram\TelegramMessenger;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.request_timeout_seconds' => 25,
            'services.jira.connect_timeout_seconds' => 20,
            'services.jira.retry_delay_us' => 300_000,
            'services.jira.cache_ttl_minutes' => 60,
            'services.jira.short_cache_ttl_minutes' => 30,
            'services.jira.max_listed_users' => 1000,
            'services.jira.api_page_size' => 50,
            'services.jira.default_max_results' => 20,
            'telegram.conversation.ttl_minutes' => 5,
            'telegram.conversation.lock_seconds' => 5,
            'telegram.conversation.done_ttl_seconds' => 60,
            'telegram.reports.context_ttl_minutes' => 30,
            'telegram.reports.description_pending_ttl_minutes' => 10,
            'telegram.reports.fetch_limit' => 200,
            'telegram.reports.callback_lock_seconds' => 30,
            'telegram.poll.error_backoff_seconds' => 3,
            'telegram.poll.default_timeout' => 30,
        ]);
    }

    /**
     * Mock a service, auto-allowing fire-and-forget TelegramMessenger
     * helpers so individual tests only set expectations they assert on.
     */
    public function mock($abstract, ?Closure $mock = null): MockInterface
    {
        $instance = parent::mock($abstract, $mock);

        if ($abstract === TelegramMessengerInterface::class || $abstract === TelegramMessenger::class) {
            foreach (['answerCallback', 'deleteMessagesQuietly'] as $method) {
                $instance->shouldReceive($method)->byDefault();
            }
        }

        if ($abstract === JiraServiceInterface::class || $abstract === JiraService::class) {
            $instance->shouldReceive('customFieldId')->with('Owner')->byDefault()->andReturnNull();
            $instance->shouldReceive('customFieldId')->with('Ticket Category')->byDefault()->andReturnNull();
        }

        if ($abstract === TaskManagementService::class) {
            $instance->shouldReceive('reset')->byDefault();
        }

        if ($abstract === AccessService::class) {
            $instance->shouldReceive('shouldShowLinkButton')->byDefault()->andReturnTrue();
        }

        return $instance;
    }

    /**
     * The reply keyboard of sent params as inline-shaped rows: each label
     * paired with the action (callback data) it stands for, resolved from
     * the label map stored for the user when the keyboard was built.
     *
     * @param  array<string, mixed>  $extra
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    protected static function replyRows(array $extra, int $userId): array
    {
        $keyboard = json_decode((string) ($extra['reply_markup'] ?? ''), true)['keyboard'] ?? [];
        $service = app(ReplyKeyboardService::class);

        return array_map(
            fn (array $row): array => array_map(
                fn (array $button): array => [
                    'text' => (string) $button['text'],
                    'callback_data' => (string) $service->resolve($userId, (string) $button['text']),
                ],
                $row,
            ),
            $keyboard,
        );
    }

    /**
     * {@see replyRows()} as JSON, for substring checks on labels and actions.
     *
     * @param  array<string, mixed>  $extra
     */
    protected static function replyMarkupWithActions(array $extra, int $userId): string
    {
        return json_encode(['inline_keyboard' => self::replyRows($extra, $userId)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
