<?php

namespace App\Services\Telegram;

use App\Contracts\TelegramMessengerInterface;
use Illuminate\Support\Facades\Cache;

class InteractiveMessages
{
    private const KEY_PREFIX = 'interactive_msgs_';

    private const TTL_MINUTES = 30;

    public function __construct(private TelegramMessengerInterface $messenger) {}

    public function track(int $userId, int $messageId): void
    {
        if ($userId <= 0 || $messageId <= 0) {
            return;
        }

        $key = self::KEY_PREFIX.$userId;

        Cache::put($key, array_merge(Cache::get($key, []), [$messageId]), now()->addMinutes(self::TTL_MINUTES));
    }

    public function hasTracked(int $userId): bool
    {
        return Cache::get(self::KEY_PREFIX.$userId, []) !== [];
    }

    public function deleteAll(int $chatId, int $userId): void
    {
        $key = self::KEY_PREFIX.$userId;
        $messageIds = Cache::get($key, []);

        if (empty($messageIds)) {
            return;
        }

        Cache::forget($key);

        $this->messenger->deleteMessagesQuietly($chatId, $messageIds);
    }
}
