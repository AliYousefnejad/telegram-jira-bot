<?php

namespace App\Services\Telegram;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Resources\CommonMessageResource;
use App\Resources\KeyboardResource;
use App\Resources\UserResource;
use App\Services\Jira\SyncService;
use App\Support\ReplyMarkup;

class BotInfoService
{
    public function __construct(
        private JiraServiceInterface $jira,
        private SyncService $sync,
        private TelegramMessengerInterface $messenger,
        private AccessService $access,
        private ReplyKeyboardService $replyKeyboard,
    ) {}

    public function start(int $chatId, string $firstName, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null, ?int $userId = null): void
    {
        $this->forgetFlowKeyboard($userId);
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $showLink = $this->access->shouldShowLinkButton($userId);

        $this->messenger->send($chatId, CommonMessageResource::welcome($firstName), $isGroup, $messageId, array_merge($extra, [
            'reply_markup' => ReplyMarkup::toJson(KeyboardResource::mainMenu($showLink)),
        ]));
    }

    public function help(int $chatId, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null, ?int $userId = null): void
    {
        $this->forgetFlowKeyboard($userId);
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $showLink = $this->access->shouldShowLinkButton($userId);

        $this->messenger->send($chatId, CommonMessageResource::helpText(), $isGroup, $messageId, array_merge($extra, [
            'reply_markup' => ReplyMarkup::toJson(KeyboardResource::mainMenu($showLink)),
        ]));
    }

    // About command removed — uncomment to restore.
    // public function about(int $chatId, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null): void
    // {
    //     $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
    //
    //     $this->messenger->send($chatId, CommonMessageResource::about(), $isGroup, $messageId, $extra);
    // }

    public function showMainMenu(int $chatId, int $messageId = 0, ?int $messageThreadId = null, ?int $userId = null): void
    {
        $this->forgetFlowKeyboard($userId);
        $text = __('keyboard.main_menu_text');
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
        $showLink = $this->access->shouldShowLinkButton($userId);

        if ($messageId) {
            $this->messenger->replyToMessage($chatId, $text, $messageId, array_merge($extra, [
                'reply_markup' => ReplyMarkup::toJson(KeyboardResource::mainMenu($showLink)),
            ]));
        } else {
            $this->messenger->sendMessage($chatId, $text, array_merge($extra, [
                'reply_markup' => ReplyMarkup::toJson(KeyboardResource::mainMenu($showLink)),
            ]));
        }
    }

    /** The main menu replaces any flow keyboard, so its label map goes too. */
    private function forgetFlowKeyboard(?int $userId): void
    {
        if ($userId !== null) {
            $this->replyKeyboard->forget($userId);
        }
    }

    // Projects command removed — uncomment to restore.
    // public function sendProjects(int $chatId, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null): void
    // {
    //     $projects = $this->jira->getProjects();
    //     $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
    //
    //     if (empty($projects)) {
    //         $this->messenger->send($chatId, CommonMessageResource::noProjects(), $isGroup, $messageId, $extra);
    //
    //         return;
    //     }
    //
    //     $this->messenger->send($chatId, CommonMessageResource::projectsHeader()."\n".ProjectResource::toListMultiple($projects), $isGroup, $messageId, $extra);
    // }

    public function listUsers(int $chatId, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null): void
    {
        $users = $this->jira->getUsers();
        $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];

        if (empty($users)) {
            $this->messenger->send($chatId, CommonMessageResource::noUsers(), $isGroup, $messageId, $extra);

            return;
        }

        $users = $this->sync->syncUserEmails($users);

        $this->messenger->send($chatId, CommonMessageResource::usersHeader()."\n".UserResource::toListMultiple($users), $isGroup, $messageId, $extra);
    }

    // Boards command removed — uncomment to restore.
    // public function sendBoards(int $chatId, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null): void
    // {
    //     $boards = $this->jira->getBoards();
    //     $extra = $messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [];
    //
    //     if (empty($boards)) {
    //         $this->messenger->send($chatId, CommonMessageResource::noBoards(), $isGroup, $messageId, $extra);
    //
    //         return;
    //     }
    //
    //     $this->messenger->send($chatId, CommonMessageResource::boardsHeader()."\n".BoardResource::toListMultiple($boards), $isGroup, $messageId, $extra);
    // }
}
