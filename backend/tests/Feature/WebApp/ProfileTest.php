<?php

namespace Tests\Feature\WebApp;

use App\Models\JiraUser;
use PHPUnit\Framework\Attributes\Test;

class ProfileTest extends WebAppTestCase
{
    private const JIRA_PROFILE = [
        'name' => 'admin.jira',
        'displayName' => 'Jane Doe',
        'emailAddress' => 'ali@example.com',
        'timeZone' => 'Asia/Tehran',
        'active' => true,
        'avatarUrls' => ['48x48' => 'https://jira.example.com/secure/useravatar?ownerId=admin.jira'],
    ];

    #[Test]
    public function me_answers_from_the_stored_link_at_once_and_fetches_the_jira_profile_afterwards(): void
    {
        $this->linkAdmin(['display_name' => 'Stored Name', 'email' => 'stored@example.com']);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUser')->with('admin.jira')->once()->andReturn(self::JIRA_PROFILE));
        $this->withoutDefer();

        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['jira' => ['name' => 'Stored Name', 'email' => 'stored@example.com', 'auth_method' => 'email', 'token_status' => 'none']]);

        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson([
                'telegram' => ['id' => self::ADMIN_ID, 'username' => 'admin_tg', 'name' => 'Admin User'],
                'jira' => [
                    'name' => 'Jane Doe', 'email' => 'ali@example.com', 'username' => 'admin.jira',
                    'time_zone' => 'Asia/Tehran', 'active' => true, 'has_avatar' => true,
                    'auth_method' => 'email', 'token_status' => 'none',
                ],
            ]);
    }

    #[Test]
    public function me_keeps_the_stored_link_when_jira_has_no_profile(): void
    {
        $this->linkAdmin(['display_name' => 'Stored Name', 'email' => 'stored@example.com']);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUser')->andReturnNull());
        $this->withoutDefer();

        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))->assertOk();
        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['jira' => ['name' => 'Stored Name', 'email' => 'stored@example.com', 'has_avatar' => false]]);
    }

    #[Test]
    public function me_reports_how_the_user_signed_in(): void
    {
        $this->linkAdmin();
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUser')->andReturnNull());

        JiraUser::query()->update(['auth_method' => 'pat']);
        JiraUser::query()->first()->forceFill(['jira_pat' => 'secret-token'])->save();
        $response = $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))->assertOk();
        $response->assertJson(['jira' => ['auth_method' => 'pat', 'token_status' => 'ok']]);
        $this->assertStringNotContainsString('secret-token', $response->getContent());

        JiraUser::query()->first()->forceFill(['jira_pat' => null])->save();
        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['jira' => ['auth_method' => 'pat', 'token_status' => 'expired']]);
    }

    #[Test]
    public function the_avatar_is_proxied_from_jira(): void
    {
        $this->linkAdmin();
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('getUser')->andReturn(self::JIRA_PROFILE);
            $jira->shouldReceive('fetchAvatar')->with(self::JIRA_PROFILE['avatarUrls']['48x48'])->andReturn(['body' => 'PNGDATA', 'type' => 'image/png']);
        });

        $response = $this->get('/api/webapp/me/avatar', $this->tmaHeaders($this->telegramUser()))->assertOk();

        $this->assertSame('PNGDATA', $response->getContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_missing_avatar_is_a_404(): void
    {
        $this->linkAdmin();
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUser')->andReturn(['displayName' => 'No Avatar']));

        $this->get('/api/webapp/me/avatar', $this->tmaHeaders($this->telegramUser()))->assertNotFound();
    }
}
