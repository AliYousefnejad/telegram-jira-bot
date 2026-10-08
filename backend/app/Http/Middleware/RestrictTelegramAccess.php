<?php

namespace App\Http\Middleware;

use App\Contracts\TelegramMessengerInterface;
use App\Resources\CommonMessageResource;
use App\Services\Telegram\AccessService;
use App\Support\TelegramChat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Chat;
use Telegram\Bot\Objects\Update;
use Telegram\Bot\Objects\User;

class RestrictTelegramAccess
{
    public function __construct(
        private TelegramMessengerInterface $messenger,
        private AccessService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $update = $this->parseUpdate($request);

        [$chat, $from] = $this->resolveChatAndUser($update);

        if ($chat === null || $from === null) {
            return response()->json(['ok' => true]);
        }

        $chatId = (int) $chat->get('id', 0);
        $chatType = $chat->get('type', 'private');
        $isGroup = TelegramChat::isGroup($chatType);
        $userId = (int) $from->get('id');

        $denialReason = $this->access->getDenialReason($chatId, $userId, $isGroup);

        if ($denialReason !== 'allowed') {
            $this->reject($update, $chatId, $denialReason);

            return response()->json(['ok' => true]);
        }

        return $next($request);
    }

    private function parseUpdate(Request $request): Update
    {
        $rawBody = $request->getContent();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        $update = new Update($decoded ?? []);

        $request->attributes->set('telegram_update', $update);

        return $update;
    }

    /**
     * @return array{0: Chat|null, 1: User|null}
     */
    private function resolveChatAndUser(Update $update): array
    {
        if ($callbackQuery = $update->getCallbackQuery()) {
            return [
                $callbackQuery->getMessage()?->get('chat'),
                $callbackQuery->getFrom(),
            ];
        }

        $message = $update->getMessage();

        if ($message->isEmpty()) {
            return [null, null];
        }

        return [$message->get('chat'), $message->get('from')];
    }

    private function reject(Update $update, int $chatId, string $denialReason): void
    {
        $errorText = $denialReason === 'user_not_admin'
            ? CommonMessageResource::notAuthorizedUser()
            : CommonMessageResource::notAuthorizedGroup();

        // Extract forum topic thread ID so rejection lands in the correct topic.
        if ($callbackQuery = $update->getCallbackQuery()) {
            $threadId = $callbackQuery->getMessage()?->get('message_thread_id');
        } else {
            $threadId = $update->getMessage()->get('message_thread_id');
        }
        $messageThreadId = $threadId !== null ? (int) $threadId : null;
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if ($update->getCallbackQuery()) {
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $update->getCallbackQuery()->getId(),
                    'text' => $errorText,
                    'show_alert' => true,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        $message = $update->getMessage();
        $messageId = (int) $message->get('message_id', 0);

        if ($messageId > 0) {
            $this->messenger->replyToMessage($chatId, $errorText, $messageId, $extra);
        }
    }
}
