<?php

namespace App\Services;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Models\JiraUser;
use App\Resources\CommonMessageResource;
use App\Resources\LinkMessageResource;
use App\Services\Jira\JiraService;
use App\Services\Telegram\ReplyKeyboardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Links a Telegram user to a Jira account, by Jira email (acts with the bot's token) or by the
 * user's own Personal Access Token (acts as the user; the token proves who they are).
 * The bot's /link uses email; TechDesk offers both.
 */
class AccountLinkService
{
    public const OK = 'ok';

    public const INVALID_EMAIL = 'invalid_email';

    public const NOT_FOUND = 'not_found';

    public const DUPLICATE = 'duplicate_accounts';

    public const LINKED_ELSEWHERE = 'linked_elsewhere';

    public const INVALID_TOKEN = 'invalid_token';

    public function __construct(
        private JiraServiceInterface $jira,
        private TelegramMessengerInterface $messenger,
        private ReplyKeyboardService $replyKeyboard,
    ) {}

    /**
     * Link by the full Jira email: it must match exactly one active Jira user that is not already
     * linked to another Telegram account. Accounts are never listed.
     *
     * @return array{status: string, user?: JiraUser}
     */
    public function linkByEmail(int $telegramUserId, string $email, string $username = ''): array
    {
        $email = trim($email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => self::INVALID_EMAIL];
        }

        $matches = array_values(array_filter(
            $this->jira->getUsers($email),
            fn (mixed $user): bool => is_array($user)
                && strcasecmp((string) ($user['emailAddress'] ?? ''), $email) === 0
                && ($user['active'] ?? true) !== false,
        ));

        if ($matches === []) {
            return ['status' => self::NOT_FOUND];
        }

        if (count($matches) > 1) {
            return ['status' => self::DUPLICATE];
        }

        $jiraId = JiraService::userId($matches[0]);
        $holder = JiraUser::query()->where('jira_account_id', $jiraId)->whereNotNull('telegram_admin_id')->value('telegram_admin_id');

        if ($holder !== null && (int) $holder !== $telegramUserId) {
            return ['status' => self::LINKED_ELSEWHERE];
        }

        $user = $this->store($telegramUserId, $jiraId, (string) ($matches[0]['displayName'] ?? $jiraId), $email, $username, [
            'auth_method' => JiraUser::AUTH_EMAIL, 'jira_pat' => null, 'pat_verified_at' => null,
        ]);

        return ['status' => self::OK, 'user' => $user];
    }

    /**
     * Link by the user's own Personal Access Token. Jira tells who owns it, which proves the account
     * belongs to this person, so a link held by another Telegram account moves here (and that
     * account is told).
     *
     * @return array{status: string, user?: JiraUser}
     */
    public function linkByToken(int $telegramUserId, string $token, string $username = ''): array
    {
        $token = trim($token);
        $owner = $token === '' ? null : $this->jira->verifyToken($token);

        if ($owner === null) {
            return ['status' => self::INVALID_TOKEN];
        }

        $jiraId = JiraService::userId($owner);
        $name = (string) ($owner['displayName'] ?? $jiraId);
        $previous = JiraUser::query()->where('jira_account_id', $jiraId)->whereNotNull('telegram_admin_id')->value('telegram_admin_id');

        $user = $this->store($telegramUserId, $jiraId, $name, $owner['emailAddress'] ?? null, $username, [
            'auth_method' => JiraUser::AUTH_PAT, 'jira_pat' => $token, 'pat_verified_at' => now(),
        ]);

        if ($previous !== null && (int) $previous !== $telegramUserId) {
            $this->clearSession((int) $previous);
            $this->notify((int) $previous, LinkMessageResource::takenOver($name));
        }

        return ['status' => self::OK, 'user' => $user];
    }

    /** Keep the link but act with the bot's shared token (e.g. after the user's token expired). */
    public function useBotToken(int $telegramUserId): ?JiraUser
    {
        $user = JiraUser::query()->where('telegram_admin_id', $telegramUserId)->first();
        $user?->forceFill(['auth_method' => JiraUser::AUTH_EMAIL, 'jira_pat' => null, 'pat_verified_at' => null])->save();

        return $user;
    }

    /** Log out: remove the link and the stored token, and drop any half-done bot or app work. */
    public function unlink(int $telegramUserId): bool
    {
        $updated = JiraUser::query()->where('telegram_admin_id', $telegramUserId)->update([
            'telegram_admin_id' => null,
            'auth_method' => JiraUser::AUTH_EMAIL,
            'jira_pat' => null,
            'pat_verified_at' => null,
        ]);

        $this->clearSession($telegramUserId);

        return $updated > 0;
    }

    /** The bot's /link: email only; it points to TechDesk for signing in with a token. */
    public function linkAccount(int $chatId, string $email, int $telegramUserId, bool $isGroup = false, int $messageId = 0, ?int $messageThreadId = null, string $username = ''): void
    {
        $extra = $this->withMainMenu($messageThreadId !== null ? ['message_thread_id' => $messageThreadId] : [], $telegramUserId, $chatId);

        $existing = JiraUser::query()->where('telegram_admin_id', $telegramUserId)->first();

        if ($existing) {
            $this->messenger->sendMessage($chatId, LinkMessageResource::alreadyLinked($existing->display_name), $extra);

            return;
        }

        try {
            $result = $this->linkByEmail($telegramUserId, $email, $username);
        } catch (Throwable $e) {
            Log::error('Link error: '.$e->getMessage());
            $this->messenger->sendMessage($chatId, CommonMessageResource::forThrowable($e, LinkMessageResource::linkError()), $extra);

            return;
        }

        $text = match ($result['status']) {
            self::OK => LinkMessageResource::linkSuccess(isset($result['user']) ? $result['user']->display_name : '?'),
            self::NOT_FOUND => LinkMessageResource::linkNotFound(trim($email)),
            self::DUPLICATE => LinkMessageResource::duplicate(trim($email)),
            self::LINKED_ELSEWHERE => LinkMessageResource::linkedElsewhere(),
            default => LinkMessageResource::usageLink(),
        };

        $this->messenger->sendMessage($chatId, $text, $extra);
    }

    /**
     * An account picked from the old multi-account picker (buttons still in old chats). The account
     * is re-checked with the same rules as linkByEmail.
     */
    public function handleLinkCallback(int $chatId, string $email, string $userId, int $telegramUserId, ?int $messageThreadId = null, string $username = ''): void
    {
        $this->linkAccount($chatId, $email, $telegramUserId, false, 0, $messageThreadId, $username);
    }

    /**
     * One row per Jira account: reuse it (so logging out and in again never duplicates rows) and
     * move this Telegram user onto it.
     *
     * @param  array<string, mixed>  $auth
     */
    private function store(int $telegramUserId, string $jiraId, string $name, ?string $email, string $username, array $auth): JiraUser
    {
        return DB::transaction(function () use ($telegramUserId, $jiraId, $name, $email, $username, $auth): JiraUser {
            JiraUser::query()->where('telegram_admin_id', $telegramUserId)->where('jira_account_id', '!=', $jiraId)->update([
                'telegram_admin_id' => null, 'auth_method' => JiraUser::AUTH_EMAIL, 'jira_pat' => null, 'pat_verified_at' => null,
            ]);

            $row = JiraUser::query()->where('jira_account_id', $jiraId)->orderByDesc('telegram_admin_id')->first();

            if (! $row instanceof JiraUser) {
                $row = new JiraUser(['jira_account_id' => $jiraId]);
            }

            $row->forceFill(array_merge([
                'display_name' => $name,
                'email' => $email ?? $row->email,
                'telegram_admin_id' => $telegramUserId,
                'telegram_username' => $username !== '' ? $username : $row->telegram_username,
            ], $auth))->save();

            return $row;
        });
    }

    /** Staged task edits and an open bot flow belong to the old identity. */
    private function clearSession(int $telegramUserId): void
    {
        Cache::forget('rpt_ctx_'.$telegramUserId);
        Cache::forget('conv_'.$telegramUserId);
    }

    private function notify(int $telegramUserId, string $text): void
    {
        try {
            $this->messenger->sendMessage($telegramUserId, $text);
        } catch (Throwable $e) {
            Log::info('Could not tell a Telegram user their Jira link moved: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function withMainMenu(array $extra, int $telegramUserId, int $chatId): array
    {
        return array_merge($extra, $this->replyKeyboard->mainMenu($telegramUserId, $chatId));
    }
}
