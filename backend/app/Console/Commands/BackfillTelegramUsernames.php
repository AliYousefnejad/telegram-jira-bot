<?php

namespace App\Console\Commands;

use App\Models\JiraUser;
use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class BackfillTelegramUsernames extends Command
{
    protected $signature = 'telegram:backfill-usernames';

    protected $description = 'Backfill telegram_username for existing linked users via Telegram API';

    public function handle(): int
    {
        $users = JiraUser::whereNull('telegram_username')
            ->whereNotNull('telegram_admin_id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users need backfilling.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} user(s) to backfill.");

        $success = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $chat = Telegram::getChat(['chat_id' => $user->telegram_admin_id]);
                $username = $chat->get('username') ?? '';

                if ($username === '') {
                    $username = (string) $user->telegram_admin_id;
                }

                $user->update(['telegram_username' => $username]);
                $success++;

                $this->line("  ✔ {$user->jira_account_id} → @{$username}");
            } catch (\Throwable $e) {
                $failed++;

                $this->error("  ✘ {$user->jira_account_id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done: {$success} updated, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
