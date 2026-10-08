<?php

namespace App\Support;

/**
 * Validates the `initData` string Telegram hands a Mini App on launch.
 *
 * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class TelegramInitData
{
    /**
     * Returns the launch fields (with `user` decoded) when the signature is valid
     * and not older than $maxAgeSeconds, otherwise null.
     *
     * @return array{user: array<string, mixed>, auth_date: int, start_param?: string, chat_type?: string, chat_instance?: string}|null
     */
    public static function validate(string $initData, string $botToken, int $maxAgeSeconds): ?array
    {
        if ($initData === '' || $botToken === '') {
            return null;
        }

        $fields = [];

        foreach (explode('&', $initData) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[urldecode($key)] = urldecode($value);
        }

        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);

        if ($hash === '') {
            return null;
        }

        ksort($fields);
        $dataCheckString = implode("\n", array_map(
            fn (string $key, string $value): string => "{$key}={$value}",
            array_keys($fields),
            $fields,
        ));

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        if (! hash_equals(hash_hmac('sha256', $dataCheckString, $secret), $hash)) {
            return null;
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if ($authDate === 0 || ($maxAgeSeconds > 0 && time() - $authDate > $maxAgeSeconds)) {
            return null;
        }

        $user = json_decode($fields['user'] ?? '', true);

        if (! is_array($user) || ! isset($user['id'])) {
            return null;
        }

        return array_filter([
            'user' => $user,
            'auth_date' => $authDate,
            'start_param' => $fields['start_param'] ?? null,
            'chat_type' => $fields['chat_type'] ?? null,
            'chat_instance' => $fields['chat_instance'] ?? null,
        ], fn ($value): bool => $value !== null);
    }
}
