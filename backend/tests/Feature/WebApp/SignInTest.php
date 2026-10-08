<?php

namespace Tests\Feature\WebApp;

use App\Contracts\TelegramMessengerInterface;
use App\Models\JiraUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('security')]
class SignInTest extends WebAppTestCase
{
    private const ME = ['name' => 'ali.y', 'displayName' => 'Ali Y', 'emailAddress' => 'ali@example.com', 'active' => true];

    private function signIn(array $body): TestResponse
    {
        return $this->postJson('/api/webapp/link', $body, $this->tmaHeaders($this->telegramUser()));
    }

    #[Test]
    public function an_exact_email_match_links_the_account_with_the_bot_token(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUsers')->with('Ali@example.com')->andReturn([self::ME, ['name' => 'ali.yy', 'emailAddress' => 'ali.yy@example.com']]));

        $this->signIn(['method' => 'email', 'email' => 'Ali@example.com'])
            ->assertOk()
            ->assertJson(['jira' => ['name' => 'Ali Y', 'auth_method' => 'email']]);

        $this->assertDatabaseHas('jira_users', ['telegram_admin_id' => self::ADMIN_ID, 'jira_account_id' => 'ali.y', 'auth_method' => 'email', 'jira_pat' => null]);
    }

    #[Test]
    #[DataProvider('emailRefusals')]
    public function email_sign_in_refuses_without_listing_accounts(array $jiraUsers, ?int $heldBy, string $error, string $email = 'ali@example.com'): void
    {
        if ($heldBy !== null) {
            JiraUser::factory()->create(['jira_account_id' => 'ali.y', 'telegram_admin_id' => $heldBy]);
        }
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUsers')->andReturn($jiraUsers));

        $this->signIn(['method' => 'email', 'email' => $email])
            ->assertUnprocessable()
            ->assertExactJson(['error' => $error]);
    }

    /** @return array<string, array{0: array<int, array<string, mixed>>, 1: ?int, 2: string, 3?: string}> */
    public static function emailRefusals(): array
    {
        return [
            'no such email' => [[], null, 'not_found'],
            'only a partial match' => [[['name' => 'ali.yy', 'emailAddress' => 'ali.yy@example.com']], null, 'not_found'],
            'inactive account' => [[array_merge(self::ME, ['active' => false])], null, 'not_found'],
            'two active accounts' => [[self::ME, ['name' => 'ali.2', 'emailAddress' => 'ali@example.com']], null, 'duplicate_accounts'],
            'linked to another telegram' => [[self::ME], 999, 'linked_elsewhere'],
            'not an email' => [[], null, 'invalid_email', 'ali.example.com'],
        ];
    }

    #[Test]
    public function a_personal_token_links_the_owner_and_is_stored_encrypted(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('verifyToken')->with('NjM0-personal-token-abcdefghij')->andReturn(self::ME));

        $response = $this->signIn(['method' => 'pat', 'token' => 'NjM0-personal-token-abcdefghij'])
            ->assertOk()
            ->assertJson(['jira' => ['name' => 'Ali Y', 'auth_method' => 'pat']]);

        $this->assertStringNotContainsString('personal-token', $response->getContent());
        $stored = DB::table('jira_users')->where('telegram_admin_id', self::ADMIN_ID)->value('jira_pat');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('personal-token', $stored);
        $this->assertSame('NjM0-personal-token-abcdefghij', JiraUser::query()->where('telegram_admin_id', self::ADMIN_ID)->first()->jira_pat);
    }

    #[Test]
    public function a_token_jira_refuses_is_rejected(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('verifyToken')->andReturnNull());

        $this->signIn(['method' => 'pat', 'token' => 'wrong-token-wrong-token-wrong'])
            ->assertUnprocessable()
            ->assertExactJson(['error' => 'invalid_token']);
        $this->assertDatabaseMissing('jira_users', ['telegram_admin_id' => self::ADMIN_ID]);
    }

    #[Test]
    public function a_token_proves_ownership_so_the_account_moves_and_the_old_telegram_is_told(): void
    {
        JiraUser::factory()->create(['jira_account_id' => 'ali.y', 'telegram_admin_id' => 555]);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('verifyToken')->andReturn(self::ME));
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage')->once()
            ->withArgs(fn (int $chatId, string $text) => $chatId === 555 && str_contains($text, 'Ali Y'));

        $this->signIn(['method' => 'pat', 'token' => 'NjM0-personal-token-abcdefghij'])->assertOk();

        $this->assertSame(1, JiraUser::query()->where('jira_account_id', 'ali.y')->count());
        $this->assertDatabaseHas('jira_users', ['jira_account_id' => 'ali.y', 'telegram_admin_id' => self::ADMIN_ID, 'auth_method' => 'pat']);
    }

    #[Test]
    public function logging_out_and_in_again_reuses_the_same_row(): void
    {
        $this->linkAdmin(['jira_account_id' => 'ali.y']);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getUsers')->andReturn([self::ME]));

        $this->deleteJson('/api/webapp/link', [], $this->tmaHeaders($this->telegramUser()))->assertOk()->assertExactJson(['logged_out' => true]);
        $this->assertDatabaseHas('jira_users', ['jira_account_id' => 'ali.y', 'telegram_admin_id' => null]);

        $this->signIn(['method' => 'email', 'email' => 'ali@example.com'])->assertOk();
        $this->assertSame(1, JiraUser::query()->where('jira_account_id', 'ali.y')->count());
    }

    #[Test]
    public function logout_wipes_the_stored_token(): void
    {
        JiraUser::factory()->withPat()->create(['telegram_admin_id' => self::ADMIN_ID]);

        $this->deleteJson('/api/webapp/link', [], $this->tmaHeaders($this->telegramUser()))->assertOk();

        $this->assertDatabaseMissing('jira_users', ['telegram_admin_id' => self::ADMIN_ID]);
        $this->assertSame(0, JiraUser::query()->whereNotNull('jira_pat')->count());
    }

    #[Test]
    public function switching_to_the_bot_token_keeps_the_link_and_drops_the_token(): void
    {
        JiraUser::factory()->withExpiredPat()->create(['telegram_admin_id' => self::ADMIN_ID]);

        $this->postJson('/api/webapp/link/bot-token', [], $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['jira' => ['auth_method' => 'email']]);

        $this->assertDatabaseHas('jira_users', ['telegram_admin_id' => self::ADMIN_ID, 'auth_method' => 'email', 'jira_pat' => null]);
    }

    #[Test]
    public function the_request_is_validated(): void
    {
        $this->signIn(['method' => 'magic'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->signIn(['method' => 'pat'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->signIn(['method' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    #[Test]
    public function jira_being_down_is_reported(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('verifyToken')->andThrow(new RuntimeException('Jira API returned HTTP 503')));

        $this->signIn(['method' => 'pat', 'token' => 'NjM0-personal-token-abcdefghij'])->assertStatus(502)->assertExactJson(['error' => 'jira_failed']);
    }

    #[Test]
    public function only_bot_admins_can_sign_in(): void
    {
        $this->postJson('/api/webapp/link', ['method' => 'email', 'email' => 'a@b.co'], $this->tmaHeaders($this->telegramUser(4444)))->assertForbidden();
    }
}
