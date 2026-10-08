<?php

namespace Tests\Feature\Services;

use App\Contracts\JiraServiceInterface;
use App\Models\JiraUser;
use App\Services\Jira\JiraCredentials;
use App\Services\Jira\JiraService;
use App\Services\Jira\TaskOwnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JiraCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private JiraCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jira.base_url' => 'https://jira.example.com', 'services.jira.pat' => 'bot-shared-token']);
        $this->credentials = app(JiraCredentials::class);
    }

    #[Test]
    public function each_bot_update_acts_as_its_sender_and_is_cleared_afterwards(): void
    {
        JiraUser::factory()->withPat('own-token')->create(['telegram_admin_id' => 42]);
        $seen = null;

        $rejected = $this->credentials->forTelegramUser(42, function () use (&$seen) {
            $seen = $this->credentials->userToken();
        });

        $this->assertFalse($rejected);
        $this->assertSame('own-token', $seen);
        $this->assertNull($this->credentials->userToken());
    }

    #[Test]
    public function an_update_from_a_user_with_an_expired_token_is_not_run(): void
    {
        JiraUser::factory()->withExpiredPat()->create(['telegram_admin_id' => 42]);
        $ran = false;

        $this->assertTrue($this->credentials->forTelegramUser(42, function () use (&$ran) {
            $ran = true;
        }));
        $this->assertFalse($ran);
    }

    #[Test]
    public function a_token_rejected_during_an_update_is_reported(): void
    {
        JiraUser::factory()->withPat('revoked')->create(['telegram_admin_id' => 42]);
        Http::fake(['jira.example.com/*' => Http::response([], 401)]);

        $rejected = $this->credentials->forTelegramUser(42, function () {
            try {
                app(JiraService::class)->getIssue('TS-1');
            } catch (\Throwable) {
                // bot flows catch everything; the rejection must still surface
            }
        });

        $this->assertTrue($rejected);
        $this->assertTrue(JiraUser::query()->where('telegram_admin_id', 42)->first()->tokenExpired());
    }

    #[Test]
    public function caches_are_kept_apart_per_identity(): void
    {
        $user = JiraUser::factory()->withPat('own-token')->create(['telegram_admin_id' => 42]);

        $this->assertSame('bot', $this->credentials->cacheScope());
        $this->credentials->actAs($user);
        $this->assertStringStartsWith('u_', $this->credentials->cacheScope());
        $this->assertSame('bot', $this->credentials->asBot(fn () => $this->credentials->cacheScope()));
    }

    #[Test]
    public function owner_sync_always_uses_the_bot_token(): void
    {
        $user = JiraUser::factory()->withPat('own-token')->create(['telegram_admin_id' => 42]);
        $this->credentials->actAs($user);

        $this->mock(JiraServiceInterface::class, function ($jira): void {
            $jira->shouldReceive('customFieldId')->with('Owner')->andReturn('customfield_owner');
            $jira->shouldReceive('optionIdForCode')->andReturn('10');
            $jira->shouldReceive('updateIssueField')->once()->andReturnUsing(function () {
                $this->assertFalse(app(JiraCredentials::class)->isUserToken(), 'Owner sync is system work.');
            });
        });

        $this->assertTrue(app(TaskOwnerService::class)->setOwner('TS-1', 'L1'));
        $this->assertTrue($this->credentials->isUserToken(), 'The user identity comes back afterwards.');
    }

    #[Test]
    public function verify_token_asks_jira_who_owns_it(): void
    {
        Http::fake([
            'jira.example.com/rest/api/2/myself' => Http::sequence()
                ->push(['name' => 'ali.y', 'displayName' => 'Ali Y'])
                ->push(['errorMessages' => ['no']], 401),
        ]);
        $jira = app(JiraService::class);

        $this->assertSame('ali.y', $jira->verifyToken('good-token')['name']);
        $this->assertNull($jira->verifyToken('bad-token'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer good-token'));
    }
}
