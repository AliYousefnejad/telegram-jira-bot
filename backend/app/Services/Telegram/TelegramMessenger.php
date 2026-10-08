<?php

namespace App\Services\Telegram;

use App\Contracts\TelegramMessengerInterface;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;

class TelegramMessenger implements TelegramMessengerInterface
{
    private const RETRY_DELAY_MICROSECONDS = 300_000;

    private const DELETE_BATCH_SIZE = 100;

    public function __construct(
        private ConversationManager $conversation,
    ) {}

    public function sendMessage(int $chatId, string $text, array $extra = []): mixed
    {
        return $this->deliver('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $extra));
    }

    public function replyToMessage(int $chatId, string $text, int $replyToMessageId, array $extra = []): mixed
    {
        return $this->deliver('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_to_message_id' => $replyToMessageId,
        ], $extra));
    }

    public function send(int $chatId, string $text, bool $isGroup, int $messageId, array $extra = []): mixed
    {
        if ($isGroup) {
            return $this->replyToMessage($chatId, $text, $messageId, $extra);
        }

        return $this->sendMessage($chatId, $text, $extra);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $extra = []): mixed
    {
        return $this->deliver('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $extra));
    }

    public function sendTrackedMessage(int $chatId, int $userId, string $text, array $extra = []): mixed
    {
        $message = $this->deliver('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $extra));

        if ($userId > 0 && $message !== null) {
            $this->conversation->addTrackedMessage($userId, (int) $message->getMessageId());
        }

        return $message;
    }

    public function answerCallback(CallbackQuery $callbackQuery): void
    {
        if ($callbackQuery->getId() === ReplyKeyboardService::VIRTUAL_CALLBACK_ID) {
            return;
        }

        try {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to answer callback query: '.$e->getMessage());
        }
    }

    public function deleteMessagesQuietly(int $chatId, array $messageIds): void
    {
        foreach (array_chunk($messageIds, self::DELETE_BATCH_SIZE) as $batch) {
            try {
                Telegram::deleteMessages([
                    'chat_id' => $chatId,
                    'message_ids' => $batch,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete messages: '.$e->getMessage());
            }
        }
    }

    private function deliver(string $method, array $params): mixed
    {
        try {
            return Telegram::{$method}($params);
        } catch (TelegramSDKException $e) {
            if (! self::isTransientConnectionFailure($e)) {
                throw $e;
            }
        }

        Log::warning("Telegram {$method} failed with a connection issue, retrying once", [
            'exception' => $e->getMessage(),
        ]);

        usleep(self::RETRY_DELAY_MICROSECONDS);

        try {
            return Telegram::{$method}($params);
        } catch (TelegramSDKException $retryException) {
            if (self::isTransientConnectionFailure($retryException)) {
                Log::error("Telegram {$method} still unreachable after retry", [
                    'exception' => $retryException->getMessage(),
                ]);
            }

            throw $retryException;
        }
    }

    private static function isTransientConnectionFailure(TelegramSDKException $e): bool
    {
        return $e->getPrevious() instanceof ConnectException;
    }
}
