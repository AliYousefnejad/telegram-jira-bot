<?php

namespace App\Services\Telegram;

use App\Models\JiraUser;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\ChatMember;

class AccessService
{
    public function isBotAdmin(int $userId): bool
    {
        return in_array($userId, config('services.telegram.admin_ids', []), true);
    }

    public function isGroupInAllowedList(int $chatId): bool
    {
        return in_array($chatId, config('services.telegram.allowed_group_ids', []), true);
    }

    public function isChatAllowed(int $chatId, int $userId, bool $isGroup): bool
    {
        return $this->getDenialReason($chatId, $userId, $isGroup) === 'allowed';
    }

    public function getDenialReason(int $chatId, int $userId, bool $isGroup): string
    {
        if (! $this->isBotAdmin($userId)) {
            return 'user_not_admin';
        }

        if ($isGroup && ! $this->isGroupInAllowedList($chatId)) {
            return 'group_not_allowed';
        }

        return 'allowed';
    }

    public function isUserLinked(int $telegramUserId): bool
    {
        $linked = JiraUser::where('telegram_admin_id', $telegramUserId)->exists();

        if (! $linked) {
            Log::info('User not linked', ['telegram_admin_id' => $telegramUserId]);
        }

        return $linked;
    }

    /** Display name of the Jira account linked to a Telegram user, falling back to its username. */
    public function linkedJiraDisplayName(int $telegramUserId): ?string
    {
        $user = JiraUser::where('telegram_admin_id', $telegramUserId)->first();

        if ($user === null) {
            return null;
        }

        $name = trim((string) ($user->display_name ?: $user->jira_account_id));

        return $name !== '' ? $name : null;
    }

    public function isLinkedAdmin(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->isBotAdmin($userId) && $this->isUserLinked($userId);
    }

    public function shouldShowLinkButton(?int $userId): bool
    {
        return ! $this->isLinkedAdmin($userId);
    }

    public function isGroupAdmin(int $chatId, int $userId): bool
    {
        if (! $this->isBotAdmin($userId)) {
            return false;
        }

        try {
            /** @var ChatMember $member */
            $member = Telegram::getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            $status = $member->get('status', '');

            return $status === 'administrator' || $status === 'creator';
        } catch (\Throwable $e) {
            Log::warning('Failed to check group admin status', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
