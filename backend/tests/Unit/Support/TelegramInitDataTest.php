<?php

namespace Tests\Unit\Support;

use App\Support\TelegramInitData;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsTelegramInitData;
use Tests\TestCase;

class TelegramInitDataTest extends TestCase
{
    use SignsTelegramInitData;

    private const USER = ['id' => 424242, 'first_name' => 'علی', 'username' => 'ali_test', 'language_code' => 'fa'];

    #[Test]
    public function it_accepts_data_signed_with_the_bot_token(): void
    {
        $launch = TelegramInitData::validate($this->initData(self::USER, extra: ['chat_type' => 'private', 'start_param' => 'x']), self::BOT_TOKEN, 86400);

        $this->assertNotNull($launch);
        $this->assertSame(424242, $launch['user']['id']);
        $this->assertSame('علی', $launch['user']['first_name']);
        $this->assertSame('private', $launch['chat_type']);
        $this->assertSame('x', $launch['start_param']);
    }

    #[Test]
    public function it_rejects_data_signed_with_another_bot_token(): void
    {
        $this->assertNull(TelegramInitData::validate($this->initData(self::USER, botToken: '999:other'), self::BOT_TOKEN, 86400));
    }

    #[Test]
    public function it_rejects_a_changed_user(): void
    {
        $tampered = str_replace('ali_test', 'someone_else', urldecode($this->initData(self::USER)));

        $this->assertNull(TelegramInitData::validate($tampered, self::BOT_TOKEN, 86400));
    }

    #[Test]
    public function it_rejects_expired_data(): void
    {
        $this->assertNull(TelegramInitData::validate($this->initData(self::USER, time() - 90_000), self::BOT_TOKEN, 86400));
    }

    #[Test]
    public function it_ignores_age_when_max_age_is_zero(): void
    {
        $this->assertNotNull(TelegramInitData::validate($this->initData(self::USER, time() - 90_000), self::BOT_TOKEN, 0));
    }

    #[Test]
    public function it_rejects_data_without_a_hash(): void
    {
        $this->assertNull(TelegramInitData::validate('auth_date='.time().'&user=%7B%22id%22%3A1%7D', self::BOT_TOKEN, 86400));
    }

    #[Test]
    public function it_rejects_signed_data_without_a_user(): void
    {
        $fields = ['auth_date' => (string) time(), 'query_id' => 'q'];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true));

        $this->assertNull(TelegramInitData::validate(http_build_query($fields), self::BOT_TOKEN, 86400));
    }

    #[Test]
    public function it_rejects_empty_input_or_an_unset_token(): void
    {
        $this->assertNull(TelegramInitData::validate('', self::BOT_TOKEN, 86400));
        $this->assertNull(TelegramInitData::validate($this->initData(self::USER), '', 86400));
    }
}
