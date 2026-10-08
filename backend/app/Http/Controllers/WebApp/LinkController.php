<?php

namespace App\Http\Controllers\WebApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebApp\LinkRequest;
use App\Services\AccountLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signing in to TechDesk: link the Telegram user to their Jira account by email or by their own
 * Personal Access Token, switch to the bot's token, or log out. The token is never returned.
 */
class LinkController extends Controller
{
    public function __construct(private AccountLinkService $links) {}

    public function store(LinkRequest $request): JsonResponse
    {
        $user = $request->attributes->get('telegram_launch')['user'];
        $telegramId = (int) $user['id'];
        $username = (string) ($user['username'] ?? '');

        try {
            $result = $request->input('method') === 'pat'
                ? $this->links->linkByToken($telegramId, (string) $request->input('token'), $username)
                : $this->links->linkByEmail($telegramId, (string) $request->input('email'), $username);
        } catch (Throwable $e) {
            Log::warning('Mini App sign-in failed: '.$e->getMessage());

            return response()->json(['error' => 'jira_failed'], 502);
        }

        if ($result['status'] !== AccountLinkService::OK || ! isset($result['user'])) {
            return response()->json(['error' => $result['status']], 422);
        }

        return response()->json(['jira' => [
            'name' => $result['user']->display_name,
            'email' => $result['user']->email,
            'auth_method' => $result['user']->auth_method,
        ]]);
    }

    /** Keep the account but act with the bot's shared token (after an expired token, or by choice). */
    public function useBotToken(Request $request): JsonResponse
    {
        $user = $this->links->useBotToken((int) $request->attributes->get('telegram_launch')['user']['id']);

        return $user === null
            ? response()->json(['error' => 'not_linked'], 403)
            : response()->json(['jira' => ['name' => $user->display_name, 'email' => $user->email, 'auth_method' => $user->auth_method]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->links->unlink((int) $request->attributes->get('telegram_launch')['user']['id']);

        return response()->json(['logged_out' => true]);
    }
}
