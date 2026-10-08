<?php

namespace Tests\Unit;

use App\Resources\KeyboardResource;
use Tests\TestCase;

class KeyboardResourceTest extends TestCase
{
    public function test_main_menu_includes_link_button_by_default(): void
    {
        $keyboard = KeyboardResource::mainMenu();
        $this->assertTrue($keyboard['resize_keyboard']);
        $this->assertTrue($keyboard['is_persistent']);
        $this->assertHasLinkButton($keyboard);
    }

    public function test_main_menu_includes_link_button_when_show_link_is_true(): void
    {
        $keyboard = KeyboardResource::mainMenu(true);
        $this->assertHasLinkButton($keyboard);
    }

    public function test_main_menu_hides_link_button_when_show_link_is_false(): void
    {
        $keyboard = KeyboardResource::mainMenu(false);
        $rows = $keyboard['keyboard'];

        foreach ($rows as $row) {
            foreach ($row as $button) {
                $this->assertNotSame(
                    (string) __('keyboard.button.link_account'),
                    $button['text'] ?? null,
                    'Link account button should not be present when showLinkAccount is false'
                );
            }
        }

        // Verify the other essential buttons are still present
        $this->assertSame(
            (string) __('keyboard.button.register_report'),
            $rows[0][0]['text']
        );
    }

    /**
     * @param  array{keyboard: list<list<array{text: string}>>, resize_keyboard: bool, is_persistent: bool}  $keyboard
     */
    private function assertHasLinkButton(array $keyboard): void
    {
        $found = false;
        foreach ($keyboard['keyboard'] as $row) {
            foreach ($row as $button) {
                if (($button['text'] ?? '') === (string) __('keyboard.button.link_account')) {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'Link account button was not found in main menu keyboard');
    }
}
