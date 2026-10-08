<?php

namespace App\Resources;

class KeyboardResource
{
    /**
     * @return array{keyboard: list<list<array{text: string}>>, resize_keyboard: bool, is_persistent: bool}
     */
    public static function mainMenu(bool $showLinkAccount = true): array
    {
        $keyboard = [];

        if ($showLinkAccount) {
            $keyboard[] = [
                ['text' => (string) __('keyboard.button.link_account')],
            ];
        }

        $keyboard[] = [
            ['text' => (string) __('keyboard.button.register_report')],
        ];
        $keyboard[] = [
            ['text' => (string) __('keyboard.button.manage_tasks')],
            ['text' => (string) __('keyboard.button.timeline')],
        ];
        $keyboard[] = [
            ['text' => (string) __('keyboard.button.help')],
        ];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }
}
