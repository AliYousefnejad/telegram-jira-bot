<?php

namespace App\Http\Middleware;

use App\Models\JiraUser;
use App\Services\Jira\JiraCredentials;
use App\Services\Telegram\AccessService;
use App\Support\TelegramInitData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Mini App API calls by Telegram's signed launch data
 * (`Authorization: tma <initData>`). Only bot admins in a private chat with the bot pass; with the
 * `linked` parameter the admin must also have a linked Jira account, and an admin who signed in
 * with their own Personal Access Token acts as themselves in Jira for the rest of the request.
 */
class ValidateTelegramInitData
{
    /** Chats the Mini App is opened from; TechDesk works in the private chat with the bot only. */
    private const GROUP_CHATS = ['group', 'supergroup', 'channel'];

    public function __construct(
        private AccessService $access,
        private JiraCredentials $credentials,
    ) {}

    public function handle(Request $request, Closure $next, string $require = 'admin'): Response
    {
        $header = $request->headers->get('Authorization') ?? '';

        if (! str_starts_with($header, 'tma ')) {
            return $this->deny('missing_init_data', 401);
        }

        $launch = TelegramInitData::validate(
            substr($header, 4),
            (string) config('telegram.bots.mybot.token'),
            (int) config('services.telegram.webapp_max_age'),
        );

        if ($launch === null) {
            return $this->deny('invalid_init_data', 401);
        }

        if (in_array($launch['chat_type'] ?? null, self::GROUP_CHATS, true)) {
            return $this->deny('private_only', 403);
        }

        $userId = (int) $launch['user']['id'];

        if (! $this->access->isBotAdmin($userId)) {
            return $this->deny('not_admin', 403);
        }

        $this->syncUsername($userId, (string) ($launch['user']['username'] ?? ''));

        $request->attributes->set('telegram_launch', $launch);

        if ($require !== 'linked') {
            return $next($request);
        }

        $link = JiraUser::query()->where('telegram_admin_id', $userId)->first();

        if ($link === null) {
            return $this->deny('not_linked', 403);
        }

        if ($link->tokenExpired()) {
            return $this->deny('pat_invalid', 401);
        }

        $this->credentials->actAs($link);

        try {
            $response = $next($request);

            // Controllers may have turned the failure into their own error; the app needs to know.
            return $this->credentials->wasRejected() ? $this->deny('pat_invalid', 401) : $response;
        } finally {
            $this->credentials->clear();
        }
    }

    private function deny(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => $code], $status);
    }

    /** Usernames change; keep the stored one current on every Mini App call. */
    private function syncUsername(int $userId, string $username): void
    {
        JiraUser::query()->where('telegram_admin_id', $userId)
            ->where(fn ($query) => $query->whereNull('telegram_username')->orWhere('telegram_username', '!=', $username))
            ->update(['telegram_username' => $username]);
    }
}
