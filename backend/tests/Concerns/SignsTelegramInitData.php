<?php

namespace Tests\Concerns;

/**
 * Builds Mini App launch data signed the way Telegram signs it.
 */
trait SignsTelegramInitData
{
    protected const BOT_TOKEN = '123456:test-bot-token';

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, string>  $extra
     */
    protected function initData(array $user, ?int $authDate = null, array $extra = [], string $botToken = self::BOT_TOKEN): string
    {
        $fields = array_merge([
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AAH-test',
            'user' => json_encode($user, JSON_UNESCAPED_UNICODE),
        ], $extra);

        ksort($fields);
        $check = implode("\n", array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $botToken, 'WebAppData', true));

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, string>
     */
    protected function tmaHeaders(array $user): array
    {
        return ['Authorization' => 'tma '.$this->initData($user), 'Accept' => 'application/json'];
    }
}
