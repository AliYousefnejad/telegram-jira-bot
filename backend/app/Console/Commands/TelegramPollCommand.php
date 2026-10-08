<?php

namespace App\Console\Commands;

use App\Contracts\TelegramMessengerInterface;
use App\Resources\CommonMessageResource;
use App\Resources\LinkMessageResource;
use App\Services\Jira\JiraCredentials;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\UpdateProcessor;
use App\Support\TelegramChat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Update;
use Throwable;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll
                            {--timeout= : Long-poll timeout in seconds (default from config)}';

    protected $description = 'Receive bot updates via long polling — no webhook or public URL required';

    public function __construct(
        private AccessService $access,
        private TelegramMessengerInterface $messenger,
        private JiraCredentials $credentials,
    ) {
        parent::__construct();
    }

    public function handle(UpdateProcessor $processor): int
    {
        $timeout = max(1, (int) ($this->option('timeout') ?? config('telegram.poll.default_timeout')));

        Telegram::deleteWebhook();

        $this->info('Polling for updates... (press Ctrl+C to stop)');

        $offset = 0;

        while (true) {
            try {
                /** @var array<int, Update> $updates */
                $updates = Telegram::getUpdates([
                    'offset' => $offset,
                    'timeout' => $timeout,
                ]);
            } catch (Throwable $e) {
                $this->warn('Polling error: '.$e->getMessage());
                sleep(config('telegram.poll.error_backoff_seconds'));

                continue;
            }

            foreach ($updates as $update) {
                $offset = $update->getUpdateId() + 1;

                try {
                    $denialReason = $this->isAuthorized($update);

                    if ($denialReason !== 'allowed') {
                        $this->reject($update, $denialReason);

                        continue;
                    }

                    // Each update acts as its sender in Jira (their own token when they signed in with one).
                    $senderId = $this->senderId($update);
                    $tokenRejected = $this->credentials->forTelegramUser($senderId, fn () => $processor->process($update));

                    if ($tokenRejected) {
                        $this->messenger->sendMessage($senderId, LinkMessageResource::tokenRejected());
                    }
                } catch (Throwable $e) {
                    Log::error('Polling update error: '.$e->getMessage(), [
                        'update_id' => $update->getUpdateId(),
                    ]);
                    $this->warn('Update '.$update->getUpdateId().' failed: '.$e->getMessage());
                }
            }
        }
    }

    private function senderId(Update $update): int
    {
        if ($update->objectType() === null) {
            return 0;
        }

        // The message or callback query this update carries; both have the sender under "from".
        $from = $update->getRelatedObject()->get('from');

        return is_object($from) && method_exists($from, 'get') ? (int) $from->get('id', 0) : (int) ($from['id'] ?? 0);
    }

    private function isAuthorized(Update $update): string
    {
        $chatId = 0;
        $userId = 0;
        $isGroup = false;

        if ($callbackQuery = $update->getCallbackQuery()) {
            $chat = $callbackQuery->getMessage()?->get('chat');
            $from = $callbackQuery->getFrom();
        } else {
            $message = $update->getMessage();
            if ($message->isEmpty()) {
                return 'allowed';
            }
            $chat = $message->get('chat');
            $from = $message->get('from');
        }

        if ($chat === null || $from === null) {
            return 'allowed';
        }

        $chatId = (int) $chat->get('id', 0);
        $chatType = $chat->get('type', 'private');
        $isGroup = TelegramChat::isGroup($chatType);
        $userId = (int) $from->get('id');

        return $this->access->getDenialReason($chatId, $userId, $isGroup);
    }

    private function reject(Update $update, string $denialReason): void
    {
        $chatId = 0;
        $isGroup = false;
        $messageThreadId = null;
        $errorText = $denialReason === 'user_not_admin'
            ? CommonMessageResource::notAuthorizedUser()
            : CommonMessageResource::notAuthorizedGroup();

        if ($callbackQuery = $update->getCallbackQuery()) {
            $chat = $callbackQuery->getMessage()?->get('chat');
            if ($chat !== null) {
                $chatId = (int) $chat->get('id', 0);
                $isGroup = TelegramChat::isGroup($chat->get('type', 'private'));
            }
            $threadId = $callbackQuery->getMessage()?->get('message_thread_id');
            $messageThreadId = $threadId !== null ? (int) $threadId : null;

            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId(),
                    'text' => $errorText,
                    'show_alert' => true,
                ]);
            } catch (Throwable $e) {
                report($e);
            }

            return;
        }

        $message = $update->getMessage();
        if ($message->isEmpty()) {
            return;
        }

        $chat = $message->get('chat');
        if ($chat !== null) {
            $chatId = (int) $chat->get('id', 0);
            $isGroup = TelegramChat::isGroup($chat->get('type', 'private'));
        }

        $messageId = (int) $message->get('message_id', 0);
        $threadId = $message->get('message_thread_id');
        $messageThreadId = $threadId !== null ? (int) $threadId : null;

        if ($messageId > 0) {
            $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
            $this->messenger->replyToMessage($chatId, $errorText, $messageId, $extra);
        }
    }
}
