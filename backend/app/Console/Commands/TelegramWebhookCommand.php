<?php

// Webhook disabled — the bot receives updates by long polling
// (`php artisan telegram:poll`). Uncomment to restore, together with the
// webhook route in routes/api.php.
//
// namespace App\Console\Commands;
//
// use Illuminate\Console\Command;
// use Telegram\Bot\Laravel\Facades\Telegram;
// use Throwable;
//
// class TelegramWebhookCommand extends Command
// {
//     protected $signature = 'telegram:set-webhook
//                             {--info : Show the current webhook registration}
//                             {--delete : Remove the webhook registration}';
//
//     protected $description = 'Register, inspect, or remove the Telegram webhook (URL from TELEGRAM_WEBHOOK_URL)';
//
//     public function handle(): int
//     {
//         try {
//             if ($this->option('info')) {
//                 return $this->showInfo();
//             }
//
//             if ($this->option('delete')) {
//                 return $this->delete();
//             }
//
//             return $this->setWebhook();
//         } catch (Throwable $e) {
//             $this->error('Telegram API error: '.$e->getMessage());
//
//             return self::FAILURE;
//         }
//     }
//
//     private function showInfo(): int
//     {
//         $info = Telegram::getWebhookInfo();
//
//         $this->table(['Key', 'Value'], collect($info)->map(fn ($value, $key) => [$key, json_encode($value)])->values()->all());
//
//         if (empty($info['url'])) {
//             $this->warn('No webhook is registered — the bot receives no updates.');
//         }
//
//         return self::SUCCESS;
//     }
//
//     private function delete(): int
//     {
//         Telegram::deleteWebhook();
//
//         $this->info('Webhook removed.');
//
//         return self::SUCCESS;
//     }
//
//     private function setWebhook(): int
//     {
//         $url = rtrim((string) config('services.telegram.webhook_url'), '/');
//
//         if ($url === '') {
//             $this->error('TELEGRAM_WEBHOOK_URL is not set in .env (e.g. https://your-id.ngrok-free.app).');
//
//             return self::FAILURE;
//         }
//
//         if (! str_ends_with($url, '/api/telegram/webhook')) {
//             $url .= '/api/telegram/webhook';
//         }
//
//         Telegram::setWebhook(['url' => $url]);
//
//         $this->info("Webhook registered: {$url}");
//         $this->line('Note: polling (telegram:poll) and webhooks are mutually exclusive; registering a webhook stops an active poller.');
//
//         return self::SUCCESS;
//     }
// }
