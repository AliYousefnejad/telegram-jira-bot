<?php

namespace Tests\Feature\WebApp;

use App\Models\JiraUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * With the real JiraService (HTTP faked): who the Mini App acts as in Jira.
 */
#[Group('security')]
class PersonalTokenTest extends WebAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jira.pat' => 'bot-shared-token']);
    }

    private function fakeJira(int $issueStatus = 200): void
    {
        Http::fake(function (Request $request) use ($issueStatus) {
            if (str_contains($request->url(), '/rest/api/2/field')) {
                return Http::response([]);
            }

            return Http::response(['key' => 'TS-1', 'fields' => ['summary' => 'S', 'status' => ['name' => 'Raised']]], $issueStatus);
        });
    }

    private function sentTokens(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Authorization')[0] ?? null)->unique()->values()->all();
    }

    #[Test]
    public function a_user_signed_in_with_a_token_acts_as_themselves(): void
    {
        JiraUser::factory()->withPat('users-own-token')->create(['telegram_admin_id' => self::ADMIN_ID]);
        $this->fakeJira();

        $this->getJson('/api/webapp/tasks/TS-1', $this->tmaHeaders($this->telegramUser()))->assertOk();

        $this->assertSame(['Bearer users-own-token'], $this->sentTokens());
    }

    #[Test]
    public function a_user_signed_in_by_email_uses_the_bot_token(): void
    {
        $this->linkAdmin();
        $this->fakeJira();

        $this->getJson('/api/webapp/tasks/TS-1', $this->tmaHeaders($this->telegramUser()))->assertOk();

        $this->assertSame(['Bearer bot-shared-token'], $this->sentTokens());
    }

    #[Test]
    public function a_token_jira_rejects_is_wiped_and_the_app_is_told(): void
    {
        JiraUser::factory()->withPat('revoked-token')->create(['telegram_admin_id' => self::ADMIN_ID]);
        $this->fakeJira(401);

        $this->getJson('/api/webapp/tasks/TS-1', $this->tmaHeaders($this->telegramUser()))
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'pat_invalid']);

        $user = JiraUser::query()->where('telegram_admin_id', self::ADMIN_ID)->first();
        $this->assertNull($user->jira_pat);
        $this->assertTrue($user->tokenExpired());
        $this->assertNotContains('Bearer bot-shared-token', $this->sentTokens(), 'Never fall back to the bot token behind the user\'s back.');
    }

    #[Test]
    public function an_expired_token_blocks_jira_work_until_the_user_chooses(): void
    {
        JiraUser::factory()->withExpiredPat()->create(['telegram_admin_id' => self::ADMIN_ID]);
        Http::fake();

        $this->getJson('/api/webapp/tasks', $this->tmaHeaders($this->telegramUser()))
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'pat_invalid']);
        Http::assertNothingSent();

        $this->getJson('/api/webapp/me', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['jira' => ['token_status' => 'expired']]);
    }

    #[Test]
    public function the_app_only_works_in_the_private_chat_with_the_bot(): void
    {
        $this->linkAdmin();

        foreach (['group', 'supergroup', 'channel'] as $chatType) {
            $this->getJson('/api/webapp/me', ['Authorization' => 'tma '.$this->initData($this->telegramUser(), extra: ['chat_type' => $chatType])])
                ->assertForbidden()
                ->assertExactJson(['error' => 'private_only']);
        }

        foreach (['sender', 'private'] as $chatType) {
            $this->mockJira(fn ($jira) => $jira->shouldReceive('getUser')->andReturnNull());
            $this->getJson('/api/webapp/me', ['Authorization' => 'tma '.$this->initData($this->telegramUser(), extra: ['chat_type' => $chatType])])->assertOk();
        }
    }
}
