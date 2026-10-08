<?php

namespace App\Support;

use App\Models\JiraUser;

/**
 * Telegram mentions (HTML parse mode). An assignee, through the jira_users link, is
 * "Name - @username", a tg://user link on the name when they have no username, or just
 * the escaped name when they never linked Telegram. The sender is "@username" or their name.
 */
class TelegramMention
{
    private const LRI = "\xE2\x81\xA6";

    private const PDI = "\xE2\x81\xA9";

    public static function forJiraUser(?string $jiraUserId, string $displayName): string
    {
        $name = e($displayName);
        $link = $jiraUserId === null || $jiraUserId === ''
            ? null
            : JiraUser::query()->where('jira_account_id', $jiraUserId)->whereNotNull('telegram_admin_id')->first();

        if ($link === null) {
            return $name;
        }

        $username = ltrim((string) $link->telegram_username, '@');

        // Mentions are left-to-right text inside Persian messages; isolate them so "@" stays in front.
        return $username !== ''
            ? self::named($name, $username)
            : '<a href="tg://user?id='.(int) $link->telegram_admin_id.'">'.$name.'</a>';
    }

    /** @param  array<string, mixed>  $telegramUser  the `user` object of Mini App launch data */
    public static function forTelegramUser(array $telegramUser): string
    {
        $username = is_string($telegramUser['username'] ?? null) ? ltrim($telegramUser['username'], '@') : '';

        if ($username !== '') {
            return self::LRI.'@'.e($username).self::PDI;
        }

        $id = is_numeric($telegramUser['id'] ?? null) ? (int) $telegramUser['id'] : 0;
        $parts = array_filter([$telegramUser['first_name'] ?? null, $telegramUser['last_name'] ?? null], 'is_string');
        $name = trim(implode(' ', $parts));

        return '<a href="tg://user?id='.$id.'">'.e($name !== '' ? $name : (string) $id).'</a>';
    }

    /** "Jane Doe - @jane_doe"; the handle is isolated so "@" stays in front inside Persian text. */
    private static function named(string $escapedName, string $username): string
    {
        $handle = self::LRI.'@'.e($username).self::PDI;

        return $escapedName !== '' ? $escapedName.' - '.$handle : $handle;
    }
}
