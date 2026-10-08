<?php

namespace Tests\Feature\WebApp;

use App\Models\JiraUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('security')]
class AuthenticationTest extends WebAppTestCase
{
    #[Test]
    public function it_rejects_requests_without_launch_data(): void
    {
        $this->getJson('/api/webapp/me')->assertUnauthorized()->assertJson(['error' => 'missing_init_data']);
    }

    #[Test]
    public function it_rejects_launch_data_signed_with_another_token(): void
    {
        $this->getJson('/api/webapp/me', ['Authorization' => 'tma '.$this->initData($this->telegramUser(), botToken: '1:other')])
            ->assertUnauthorized()
            ->assertJson(['error' => 'invalid_init_data']);
    }

    #[Test]
    public function it_rejects_expired_launch_data(): void
    {
        $this->getJson('/api/webapp/me', ['Authorization' => 'tma '.$this->initData($this->telegramUser(), time() - 100_000)])
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_telegram_users_who_are_not_bot_admins(): void
    {
        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser(555)))
            ->assertForbidden()
            ->assertJson(['error' => 'not_admin']);
    }

    #[Test]
    public function an_unlinked_admin_can_read_their_profile_but_not_tasks(): void
    {
        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['telegram' => ['id' => self::ADMIN_ID, 'username' => 'admin_tg'], 'jira' => null]);

        $this->getJson('/api/webapp/tasks', $this->tmaHeaders($this->telegramUser()))
            ->assertForbidden()
            ->assertJson(['error' => 'not_linked']);
    }

    #[Test]
    public function every_call_refreshes_the_stored_telegram_username(): void
    {
        $this->linkAdmin(['telegram_username' => 'old_name']);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUser')->andReturn(null));

        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser(username: 'new_name')))->assertOk();

        $this->assertSame('new_name', JiraUser::where('telegram_admin_id', self::ADMIN_ID)->value('telegram_username'));
    }
}
