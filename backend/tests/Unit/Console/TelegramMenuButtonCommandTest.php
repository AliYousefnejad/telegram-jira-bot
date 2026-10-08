<?php

namespace Tests\Unit\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramMenuButtonCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telegram.bots.mybot.token' => '1:TOKEN',
            'services.telegram.admin_ids' => [111, 222],
            'services.telegram.webapp_url' => 'https://support.example.com/app/index.html',
        ]);
    }

    #[Test]
    public function it_sets_the_mini_app_button_for_the_default_and_every_admin(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('telegram:menu-button')->assertSuccessful();

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => ! isset($r['chat_id']) && $r['menu_button']['web_app']['url'] === 'https://support.example.com/app/index.html');
        Http::assertSent(fn (Request $r) => ($r['chat_id'] ?? null) === 222 && $r['menu_button']['text'] === 'TechDesk');
    }

    #[Test]
    public function reset_restores_the_command_menu(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('telegram:menu-button --reset')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r['menu_button'] === ['type' => 'commands']);
    }

    #[Test]
    public function it_refuses_a_non_https_url(): void
    {
        Http::fake();

        $this->artisan('telegram:menu-button http://localhost/app')->assertFailed();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_rejected_chat_makes_the_command_fail(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true])->push(['ok' => false], 400)->push(['ok' => true])]);

        $this->artisan('telegram:menu-button')->assertFailed();
    }
}
