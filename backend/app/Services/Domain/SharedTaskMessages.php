<?php

namespace App\Services\Domain;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Models\SharedTaskMessage;
use App\Resources\TaskShareMessageResource;
use App\Services\Jira\JiraCredentials;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Message;
use Throwable;

/**
 * Task messages sent to chats stay current: after a task is edited (in TechDesk or in the bot), every
 * message sent for it in the last REFRESH_DAYS days is edited to show the task as it is now.
 */
class SharedTaskMessages
{
    private const REFRESH_DAYS = 30;

    private const REFRESH_LIMIT = 20;

    public const PARSE = ['parse_mode' => 'HTML', 'disable_web_page_preview' => true];

    public function __construct(
        private JiraServiceInterface $jira,
        private TelegramMessengerInterface $messenger,
        private JiraCredentials $credentials,
    ) {}

    /**
     * @param  mixed  $sent  what sendMessage returned (a Telegram Message, or null when it failed)
     * @param  array<string, mixed>  $sharedBy
     */
    public function remember(string $taskKey, int $chatId, mixed $sent, array $sharedBy): void
    {
        $messageId = $sent instanceof Message ? (int) $sent->get('message_id') : 0;

        if ($messageId > 0) {
            SharedTaskMessage::query()->create(['task_key' => $taskKey, 'chat_id' => $chatId, 'message_id' => $messageId, 'shared_by' => $sharedBy]);
        }
    }

    /** Re-render every recent message of the task. Never throws: the edit itself already succeeded. */
    public function refresh(string $taskKey): void
    {
        try {
            $messages = SharedTaskMessage::query()
                ->where('task_key', $taskKey)
                ->where('created_at', '>=', now()->subDays(self::REFRESH_DAYS))
                ->latest('id')
                ->limit(self::REFRESH_LIMIT)
                ->get();

            if ($messages->isEmpty()) {
                return;
            }

            // Everyone who received the message sees the same task, so it is read with the bot's token.
            $issue = $this->credentials->asBot(fn () => $this->jira->getIssue($taskKey));
        } catch (Throwable $e) {
            Log::warning("Could not refresh the chat messages of {$taskKey}: ".$e->getMessage());

            return;
        }

        if ($issue === null) {
            return;
        }

        foreach ($messages as $message) {
            try {
                $this->messenger->editMessageText($message->chat_id, $message->message_id, TaskShareMessageResource::forIssue($issue, $message->shared_by), self::PARSE);
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'message to edit not found') || str_contains($e->getMessage(), "message can't be edited")) {
                    SharedTaskMessage::query()->whereKey($message->id)->delete();
                } elseif (! str_contains($e->getMessage(), 'message is not modified')) {
                    Log::warning("Could not refresh the {$taskKey} message in chat {$message->chat_id}: ".$e->getMessage());
                }
            }
        }
    }
}
