<?php

namespace Tests\Unit;

use App\Resources\CommonMessageResource;
use App\Services\Jira\JiraService;
use App\Services\Jira\SyncService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\BotInfoService;
use App\Services\Telegram\TelegramMessenger;
use Tests\TestCase;

class BotInfoServiceTest extends TestCase
{
    public function test_start_sends_welcome_with_reply_keyboard(): void
    {
        $this->mock(JiraService::class);
        $this->mock(SyncService::class);

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('send')
            ->once()
            ->withArgs(function (int $chatId, string $text, bool $isGroup, int $messageId, array $extra): bool {
                return $chatId === 555
                    && $isGroup === false
                    && $messageId === 0
                    && $text === CommonMessageResource::welcome('Alice')
                    && isset($extra['reply_markup']);
            });

        app(BotInfoService::class)->start(555, 'Alice');
    }

    public function test_help_sends_the_note_with_the_main_menu_buttons(): void
    {
        $this->mock(JiraService::class);
        $this->mock(SyncService::class);

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('send')
            ->once()
            ->withArgs(function (int $chatId, string $text, bool $isGroup, int $messageId, array $extra): bool {
                return $chatId === 555
                    && $isGroup === false
                    && $messageId === 0
                    && $text === CommonMessageResource::helpText()
                    && isset($extra['reply_markup']);
            });

        app(BotInfoService::class)->help(555);
    }

    public function test_the_welcome_message_no_longer_references_help(): void
    {
        $this->assertFalse(str_contains(__('common.welcome.greeting', ['name' => 'Alice']), 'help'));
    }

    public function test_start_excludes_link_button_for_linked_admin(): void
    {
        $this->mock(JiraService::class);
        $this->mock(SyncService::class);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('shouldShowLinkButton')->with(123)->andReturnFalse();

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('send')
            ->once()
            ->withArgs(function (int $chatId, string $text, bool $isGroup, int $messageId, array $extra): bool {
                $markup = json_decode($extra['reply_markup'], true);
                $buttons = array_merge(...$markup['keyboard']);

                foreach ($buttons as $button) {
                    if (($button['text'] ?? '') === (string) __('keyboard.button.link_account')) {
                        return false;
                    }
                }

                return true;
            });

        app(BotInfoService::class)->start(555, 'Alice', userId: 123);
    }

    public function test_start_includes_link_button_for_unlinked_user(): void
    {
        $this->mock(JiraService::class);
        $this->mock(SyncService::class);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('shouldShowLinkButton')->with(123)->andReturnTrue();

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('send')
            ->once()
            ->withArgs(function (int $chatId, string $text, bool $isGroup, int $messageId, array $extra): bool {
                $markup = json_decode($extra['reply_markup'], true);
                $buttons = array_merge(...$markup['keyboard']);

                foreach ($buttons as $button) {
                    if (($button['text'] ?? '') === (string) __('keyboard.button.link_account')) {
                        return true;
                    }
                }

                return false;
            });

        app(BotInfoService::class)->start(555, 'Alice', userId: 123);
    }

    public function test_help_excludes_link_button_for_linked_admin(): void
    {
        $this->mock(JiraService::class);
        $this->mock(SyncService::class);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('shouldShowLinkButton')->with(123)->andReturnFalse();

        $messenger = $this->mock(TelegramMessenger::class);
        $messenger->shouldReceive('send')
            ->once()
            ->withArgs(function (int $chatId, string $text, bool $isGroup, int $messageId, array $extra): bool {
                $markup = json_decode($extra['reply_markup'], true);
                $buttons = array_merge(...$markup['keyboard']);

                foreach ($buttons as $button) {
                    if (($button['text'] ?? '') === (string) __('keyboard.button.link_account')) {
                        return false;
                    }
                }

                return true;
            });

        app(BotInfoService::class)->help(555, userId: 123);
    }
}
