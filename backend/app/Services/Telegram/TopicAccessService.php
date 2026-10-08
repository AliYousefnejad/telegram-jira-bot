<?php

namespace App\Services\Telegram;

use App\Models\AllowedTopic;
use App\Models\TelegramGroup;
use Illuminate\Support\Collection;

class TopicAccessService
{
    public function isTopicAllowed(int $chatId, ?int $messageThreadId): bool
    {
        $group = TelegramGroup::where('chat_id', $chatId)->first();

        if ($group === null) {
            return true;
        }

        $allowedCount = $group->allowedTopics()->count();

        if ($allowedCount === 0) {
            return true;
        }

        if ($messageThreadId === null) {
            return false;
        }

        return $group->allowedTopics()
            ->where('message_thread_id', $messageThreadId)
            ->exists();
    }

    public function addTopic(int $chatId, int $messageThreadId, ?string $topicName = null): void
    {
        $group = TelegramGroup::firstOrCreate(
            ['chat_id' => $chatId],
            ['is_forum' => true],
        );

        $group->allowedTopics()->updateOrCreate(
            ['message_thread_id' => $messageThreadId],
            ['topic_name' => $topicName],
        );
    }

    public function removeTopic(int $chatId, int $messageThreadId): void
    {
        $group = TelegramGroup::where('chat_id', $chatId)->first();

        if ($group === null) {
            return;
        }

        $group->allowedTopics()
            ->where('message_thread_id', $messageThreadId)
            ->delete();

        if ($group->allowedTopics()->count() === 0) {
            $group->delete();
        }
    }

    /** @return Collection<int, AllowedTopic> */
    public function getAllowedTopics(int $chatId): Collection
    {
        $group = TelegramGroup::where('chat_id', $chatId)->first();

        if ($group === null) {
            return collect();
        }

        return $group->allowedTopics()->get();
    }

    public function topicExists(int $chatId, int $messageThreadId): bool
    {
        $group = TelegramGroup::where('chat_id', $chatId)->first();

        if ($group === null) {
            return false;
        }

        return $group->allowedTopics()
            ->where('message_thread_id', $messageThreadId)
            ->exists();
    }

    public function getCachedTopicName(int $chatId, int $messageThreadId): ?string
    {
        $group = TelegramGroup::where('chat_id', $chatId)->first();

        if ($group === null) {
            return null;
        }

        $topic = $group->allowedTopics()
            ->where('message_thread_id', $messageThreadId)
            ->first();

        return $topic?->topic_name;
    }

    public function updateTopicName(int $chatId, int $messageThreadId, string $topicName): void
    {
        $group = TelegramGroup::where('chat_id', $chatId)->first();

        if ($group === null) {
            return;
        }

        $group->allowedTopics()
            ->where('message_thread_id', $messageThreadId)
            ->update(['topic_name' => $topicName]);
    }
}
