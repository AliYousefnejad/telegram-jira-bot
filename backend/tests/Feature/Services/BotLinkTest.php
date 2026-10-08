<?php

namespace Tests\Feature\Services;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Models\JiraUser;
use App\Services\AccountLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The bot's /link: email only, exact match, no account picker, and a pointer to TechDesk for tokens. */
class BotLinkTest extends TestCase
{
    use RefreshDatabase;

    private const ME = ['name' => 'ali.y', 'displayName' => 'Ali Y', 'emailAddress' => 'ali@example.com'];

    private function expectReply(callable $check): void
    {
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage')->once()
            ->withArgs(fn (int $chatId, string $text) => $check($text));
    }

    #[Test]
    public function a_match_links_and_mentions_signing_in_with_a_token(): void
    {
        $this->mock(JiraServiceInterface::class)->shouldReceive('getUsers')->andReturn([self::ME]);
        $this->expectReply(fn (string $text) => str_contains($text, 'Ali Y') && str_contains($text, 'PAT'));

        app(AccountLinkService::class)->linkAccount(10, 'ali@example.com', 42);

        $this->assertDatabaseHas('jira_users', ['telegram_admin_id' => 42, 'jira_account_id' => 'ali.y', 'auth_method' => 'email']);
    }

    #[Test]
    public function several_accounts_with_the_email_are_refused_instead_of_listed(): void
    {
        $this->mock(JiraServiceInterface::class)->shouldReceive('getUsers')->andReturn([self::ME, ['name' => 'ali.2', 'emailAddress' => 'ali@example.com']]);
        $this->expectReply(fn (string $text) => str_contains($text, 'ali@example.com') && ! str_contains($text, 'ali.2'));

        app(AccountLinkService::class)->linkAccount(10, 'ali@example.com', 42);

        $this->assertDatabaseMissing('jira_users', ['telegram_admin_id' => 42]);
    }

    #[Test]
    public function an_account_linked_to_another_telegram_is_refused(): void
    {
        JiraUser::factory()->create(['jira_account_id' => 'ali.y', 'telegram_admin_id' => 7]);
        $this->mock(JiraServiceInterface::class)->shouldReceive('getUsers')->andReturn([self::ME]);
        $this->expectReply(fn (string $text) => str_contains($text, 'TechDesk'));

        app(AccountLinkService::class)->linkAccount(10, 'ali@example.com', 42);

        $this->assertDatabaseHas('jira_users', ['jira_account_id' => 'ali.y', 'telegram_admin_id' => 7]);
    }
}
