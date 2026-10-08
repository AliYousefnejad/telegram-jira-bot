<?php

namespace App\Services\Telegram;

use App\Contracts\TelegramMessengerInterface;
use App\DTOs\ConversationState;
use App\Enums\FlowCommand;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class ConversationManager
{
    public function __construct(private FlowDefinition $definitions) {}

    private const PREFIX = 'conv_';

    private const LOCK_PREFIX = 'conv_lock_';

    private const DONE_PREFIX = 'conv_done_';

    public function start(FlowCommand $command, int $userId, int $chatId, int $messageId, bool $isGroup, bool $projectFirst = false, ?int $messageThreadId = null): void
    {
        $state = new ConversationState(
            command: $command->value,
            step: 0,
            data: [],
            chatId: $chatId,
            messageId: $messageId,
            isGroup: $isGroup,
            projectFirst: $projectFirst,
            messageThreadId: $messageThreadId,
        );

        Cache::put(self::PREFIX.$userId, $state->toArray(), now()->addMinutes(config('telegram.conversation.ttl_minutes')));
    }

    public function startIfIdle(FlowCommand $command, int $userId, int $chatId, int $messageId, bool $isGroup, bool $projectFirst = false, ?int $messageThreadId = null): bool
    {
        return (bool) $this->withLock($userId, function () use ($command, $userId, $chatId, $messageId, $isGroup, $projectFirst, $messageThreadId): bool {
            if ($this->isActive($userId)) {
                return false;
            }

            $this->start($command, $userId, $chatId, $messageId, $isGroup, $projectFirst, $messageThreadId);

            return true;
        });
    }

    public function consume(int $userId): ?ConversationState
    {
        $state = $this->withLock($userId, function () use ($userId): ?array {
            $popped = $this->pop($userId);

            if ($popped !== null) {
                Cache::put(self::DONE_PREFIX.$userId, true, now()->addSeconds(config('telegram.conversation.done_ttl_seconds')));
            }

            return $popped;
        });

        return is_array($state) ? ConversationState::fromArray($state) : null;
    }

    public function wasRecentlyConsumed(int $userId): bool
    {
        return Cache::has(self::DONE_PREFIX.$userId);
    }

    public function get(int $userId): ?array
    {
        return Cache::get(self::PREFIX.$userId);
    }

    public function advance(int $userId, int $nextStep, array $mergeData = []): void
    {
        $state = $this->get($userId);

        if ($state === null) {
            return;
        }

        $state['step'] = $nextStep;
        $state['data'] = array_merge($state['data'], $mergeData);

        Cache::put(self::PREFIX.$userId, $state, now()->addMinutes(config('telegram.conversation.ttl_minutes')));
    }

    public function setStep(int $userId, int $step, array $mergeData = []): void
    {
        $state = $this->get($userId);

        if ($state === null) {
            return;
        }

        $state['step'] = $step;
        $state['data'] = array_merge($state['data'], $mergeData);

        Cache::put(self::PREFIX.$userId, $state, now()->addMinutes(config('telegram.conversation.ttl_minutes')));
    }

    public function setData(int $userId, array $data): void
    {
        $state = $this->get($userId);

        if ($state === null) {
            return;
        }

        $state['data'] = array_merge($state['data'], $data);

        Cache::put(self::PREFIX.$userId, $state, now()->addMinutes(config('telegram.conversation.ttl_minutes')));
    }

    /** @param  array<int, string>  $keys */
    public function forgetData(int $userId, array $keys): void
    {
        $state = $this->get($userId);

        if ($state === null) {
            return;
        }

        $state['data'] = array_diff_key($state['data'], array_flip($keys));

        Cache::put(self::PREFIX.$userId, $state, now()->addMinutes(config('telegram.conversation.ttl_minutes')));
    }

    public function clear(int $userId): void
    {
        Cache::forget(self::PREFIX.$userId);
    }

    public function isActive(int $userId): bool
    {
        return Cache::has(self::PREFIX.$userId);
    }

    public function getFlow(string $commandName, bool $projectFirst = false): ?array
    {
        $command = FlowCommand::tryFrom($commandName);

        if ($command === null) {
            return null;
        }

        return $this->definitions->steps($command, $projectFirst);
    }

    public function addTrackedMessage(int $userId, int $messageId): void
    {
        $state = $this->get($userId);
        if ($state === null) {
            return;
        }

        $state['data']['_flow_messages'][] = $messageId;
        Cache::put(self::PREFIX.$userId, $state, now()->addMinutes(config('telegram.conversation.ttl_minutes')));
    }

    public function getTrackedMessages(int $userId): array
    {
        $state = $this->get($userId);

        return $state['data']['_flow_messages'] ?? [];
    }

    public function deleteTrackedMessages(int $chatId, int $userId, ?ConversationState $consumedState = null): void
    {
        $messageIds = $consumedState !== null
            ? ($consumedState->data['_flow_messages'] ?? [])
            : $this->getTrackedMessages($userId);

        if (empty($messageIds)) {
            return;
        }

        app(TelegramMessengerInterface::class)->deleteMessagesQuietly($chatId, $messageIds);

        if ($consumedState !== null) {
            return;
        }

        $state = $this->get($userId);
        if ($state !== null) {
            unset($state['data']['_flow_messages']);
            Cache::put(self::PREFIX.$userId, $state, now()->addMinutes(config('telegram.conversation.ttl_minutes')));
        }
    }

    private function withLock(int $userId, callable $callback): mixed
    {
        try {
            return Cache::lock(self::LOCK_PREFIX.$userId, config('telegram.conversation.lock_seconds'))->block(config('telegram.conversation.lock_seconds'), $callback);
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function pop(int $userId): ?array
    {
        $state = $this->get($userId);

        if ($state !== null) {
            Cache::forget(self::PREFIX.$userId);
        }

        return $state;
    }
}
