<?php

namespace App\Http\Controllers\WebApp;

use App\Contracts\JiraServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\JiraUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

class ProfileController extends Controller
{
    public function __construct(private JiraServiceInterface $jira) {}

    /**
     * The signed-in Telegram user and, when linked, their Jira profile and how they signed in.
     * Answers from what is stored or cached so the app opens fast; the Jira profile is refreshed
     * after the response when it is not cached yet.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('telegram_launch')['user'];
        $link = JiraUser::query()->where('telegram_admin_id', (int) $user['id'])->first();
        $jira = $link === null ? null : $this->cachedJiraProfile($link->jira_account_id);

        return response()->json([
            'telegram' => [
                'id' => (int) $user['id'],
                'username' => $user['username'] ?? null,
                'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')),
            ],
            'jira' => $link === null ? null : [
                'name' => (string) ($jira['displayName'] ?? $link->display_name),
                'email' => (string) ($jira['emailAddress'] ?? $link->email ?? ''),
                'username' => $link->jira_account_id,
                'time_zone' => $jira['timeZone'] ?? null,
                'active' => $jira['active'] ?? null,
                'has_avatar' => $jira === null || isset($jira['avatarUrls']['48x48']),
                'auth_method' => $link->auth_method,
                'token_status' => match (true) {
                    $link->hasPersonalToken() => 'ok',
                    $link->tokenExpired() => 'expired',
                    default => 'none',
                },
            ],
        ]);
    }

    /** The linked Jira account's avatar, proxied because Jira avatars need authentication. */
    public function avatar(Request $request): Response
    {
        $link = JiraUser::query()->where('telegram_admin_id', (int) $request->attributes->get('telegram_launch')['user']['id'])->first();
        $url = $link === null ? null : ($this->jiraProfile($link->jira_account_id)['avatarUrls']['48x48'] ?? null);
        $image = is_string($url) ? $this->jira->fetchAvatar($url) : null;

        if ($image === null) {
            return response('', 404);
        }

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @return array<string, mixed> */
    private function jiraProfile(string $jiraUserId): array
    {
        return Cache::remember(self::profileKey($jiraUserId), 600, fn (): array => $this->jira->getUser($jiraUserId) ?? []);
    }

    /** @return array<string, mixed>|null null when not cached yet (it is fetched after this response) */
    private function cachedJiraProfile(string $jiraUserId): ?array
    {
        $profile = Cache::get(self::profileKey($jiraUserId));

        if (! is_array($profile)) {
            defer(fn () => $this->jiraProfile($jiraUserId));

            return null;
        }

        return $profile;
    }

    private static function profileKey(string $jiraUserId): string
    {
        return 'webapp_jira_profile_'.md5($jiraUserId);
    }
}
