<?php

namespace App\Services\Telegram;

use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Services\AccountLinkService;
use App\Services\Jira\TaskService;
use App\Support\CallbackData;
use App\Support\CallbackDebounce;
use App\Support\TelegramChat;
use Telegram\Bot\Objects\CallbackQuery;

class CallbackService
{
    private const DEBOUNCE_SECONDS = 2;

    public function __construct(
        private TaskService $tasks,
        private AccountLinkService $accountLink,
        private TelegramMessengerInterface $messenger,
    ) {}

    public function handleCallbackQuery(CallbackQuery $callbackQuery, ?int $messageThreadId = null): void
    {
        $data = $callbackQuery->getData() ?? '';
        $chatId = $callbackQuery->getMessage()?->getChat()?->getId();

        $this->messenger->answerCallback($callbackQuery);

        if ($chatId === null) {
            return;
        }

        $callbackUserId = (int) ($callbackQuery->getFrom()?->getId() ?? 0);

        if (! CallbackDebounce::run('cbq', $callbackUserId, $data, self::DEBOUNCE_SECONDS, fn () => $this->dispatch($callbackQuery, $data, (int) $chatId, $callbackUserId, $messageThreadId))) {
            return;
        }
    }

    private function dispatch(CallbackQuery $callbackQuery, string $data, int $chatId, int $callbackUserId, ?int $messageThreadId = null): void
    {
        $editMessageId = $callbackQuery->getMessage()?->getMessageId();
        $chatType = (string) ($callbackQuery->getMessage()?->get('chat')?->get('type', 'private'));
        $isGroup = TelegramChat::isGroup($chatType);

        $parts = CallbackData::split($data, CallbackAction::Page, 2);
        if ($parts !== null) {
            $projectKey = strtoupper($parts[0]);
            $page = (int) $parts[1];
            $this->tasks->sendTasksForProject($chatId, $projectKey, false, 0, $page, $editMessageId !== null ? (int) $editMessageId : null, $messageThreadId);

            return;
        }

        $parts = CallbackData::split($data, CallbackAction::DetailPage, 2);
        if ($parts !== null) {
            $projectKey = strtoupper($parts[0]);
            $page = (int) $parts[1];
            $this->tasks->sendTasksForDetail($chatId, $projectKey, $page, $editMessageId !== null ? (int) $editMessageId : null, 0, $isGroup, $messageThreadId);

            return;
        }

        if (CallbackData::startsWith($data, CallbackAction::Assign)) {
            $taskKey = strtoupper(CallbackData::strip($data, CallbackAction::Assign));
            $this->tasks->sendUserPickerForKeyboard($chatId, $taskKey, CallbackAction::PickAssign->value, $messageThreadId);

            return;
        }

        if (CallbackData::startsWith($data, CallbackAction::Detail)) {
            $taskKey = strtoupper(CallbackData::strip($data, CallbackAction::Detail));
            $this->tasks->sendTaskDetail($chatId, $taskKey, $callbackUserId, messageThreadId: $messageThreadId);

            return;
        }

        $parts = CallbackData::linkPickParts($data);
        if ($parts !== null) {
            $telegramUserId = (int) ($callbackQuery->getFrom()?->getId() ?? 0);
            $username = (string) ($callbackQuery->getFrom()?->get('username') ?? '');
            $this->accountLink->handleLinkCallback($chatId, $parts[0], $parts[1], $telegramUserId, $messageThreadId, $username);

            return;
        }

        if (CallbackData::startsWith($data, CallbackAction::Status)) {
            $taskKey = strtoupper(CallbackData::strip($data, CallbackAction::Status));
            $this->tasks->sendStatusPickerForKeyboard($chatId, $taskKey, $messageThreadId);

            return;
        }

        if (CallbackData::startsWith($data, CallbackAction::Timeline)) {
            $taskKey = strtoupper(CallbackData::strip($data, CallbackAction::Timeline));
            $this->tasks->sendTimeline($chatId, $taskKey, $messageThreadId);

            return;
        }

        if (CallbackData::startsWith($data, CallbackAction::Push)) {
            $taskKey = strtoupper(CallbackData::strip($data, CallbackAction::Push));
            $this->tasks->sendBoardPickerForKeyboard($chatId, $taskKey, $messageThreadId);

            return;
        }

        $parts = CallbackData::split($data, CallbackAction::PickBoard, 2);
        if ($parts !== null) {
            $this->tasks->sendSprintPickerForBoard($chatId, $parts[0], (int) $parts[1], $messageThreadId);

            return;
        }

        $parts = CallbackData::split($data, CallbackAction::PickAssign, 2);
        if ($parts !== null) {
            $this->tasks->assignToTask($chatId, $parts[0], $parts[1], $messageThreadId);

            return;
        }

        $parts = CallbackData::split($data, CallbackAction::PickStatus, 2);
        if ($parts !== null) {
            $this->tasks->changeStatus($chatId, $parts[0], $parts[1], $messageThreadId);

            return;
        }

        $parts = CallbackData::split($data, CallbackAction::PickSprint, 2);
        if ($parts !== null) {
            $this->tasks->moveToSprint($chatId, $parts[0], (int) $parts[1], $messageThreadId);
        }
    }
}
