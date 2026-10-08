<?php

namespace App\Contracts;

use Telegram\Bot\Objects\CallbackQuery;

interface TelegramMessengerInterface
{
    public function sendMessage(int $chatId, string $text, array $extra = []): mixed;

    public function replyToMessage(int $chatId, string $text, int $replyToMessageId, array $extra = []): mixed;

    public function send(int $chatId, string $text, bool $isGroup, int $messageId, array $extra = []): mixed;

    public function editMessageText(int $chatId, int $messageId, string $text, array $extra = []): mixed;

    public function sendTrackedMessage(int $chatId, int $userId, string $text, array $extra = []): mixed;

    public function answerCallback(CallbackQuery $callbackQuery): void;

    public function deleteMessagesQuietly(int $chatId, array $messageIds): void;
}
