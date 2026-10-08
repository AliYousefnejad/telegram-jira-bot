<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Puts the TechDesk Mini App on the bot's menu button (next to the message box), or restores the
 * normal command menu. Sets the bot-wide default and each admin's private chat, because Telegram
 * applies a per-chat button over the default.
 */
class TelegramMenuButtonCommand extends Command
{
    protected $signature = 'telegram:menu-button
                            {url? : Mini App URL, e.g. https://support.example.com/app/index.html (default: TELEGRAM_WEBAPP_URL)}
                            {--reset : Restore the normal command menu instead}
                            {--text=TechDesk : Label of the button}';

    protected $description = 'Show the TechDesk Mini App on the bot menu button, or restore the command menu';

    public function handle(): int
    {
        $reset = (bool) $this->option('reset');
        $url = (string) ($this->argument('url') ?: config('services.telegram.webapp_url'));

        if (! $reset && ! str_starts_with($url, 'https://')) {
            $this->error('Telegram only opens Mini Apps over HTTPS. Pass the URL or set TELEGRAM_WEBAPP_URL.');

            return self::FAILURE;
        }

        $button = $reset
            ? ['type' => 'commands']
            : ['type' => 'web_app', 'text' => is_string($text = $this->option('text')) && $text !== '' ? $text : 'TechDesk', 'web_app' => ['url' => $url]];

        $targets = array_merge([null], config('services.telegram.admin_ids', []));
        $failed = 0;

        foreach ($targets as $chatId) {
            $label = $chatId === null ? 'default' : "admin chat {$chatId}";

            try {
                $ok = Http::asJson()->timeout(20)
                    ->post($this->apiUrl('setChatMenuButton'), array_filter(['chat_id' => $chatId, 'menu_button' => $button], fn ($v) => $v !== null))
                    ->json('ok') === true;
            } catch (Throwable $e) {
                $ok = false;
                $this->warn("{$label}: ".$e->getMessage());
            }

            $ok ? $this->line("✓ {$label}") : $this->error("✗ {$label}");
            $failed += $ok ? 0 : 1;
        }

        $this->info($reset ? 'Command menu restored.' : "Menu button now opens {$url}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('telegram.bots.mybot.token').'/'.$method;
    }
}
