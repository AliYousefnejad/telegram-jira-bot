<?php

namespace Tests\Unit;

use App\Models\JiraUser;
use App\Services\AccountLinkService;
use App\Services\Jira\JiraService;
use App\Services\Telegram\TelegramMessenger;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Mockery;
use Tests\TestCase;

class AccountLinkServiceTest extends TestCase
{
    public function test_link_flow_starts_when_user_is_not_linked(): void
    {
        $this->fakeJiraUserLookup([]);

        $this->mock(JiraService::class)
            ->shouldReceive('getUsers')
            ->once()
            ->with('alice@example.com')
            ->andReturn([]);
        $this->mock(TelegramMessenger::class)
            ->shouldReceive('sendMessage')
            ->once();

        app(AccountLinkService::class)->linkAccount(1, 'alice@example.com', 111);
    }

    public function test_link_flow_refuses_when_user_is_already_linked(): void
    {
        $this->fakeJiraUserLookup([
            ['jira_account_id' => 'alice', 'display_name' => 'Alice', 'email' => 'alice@example.com', 'telegram_admin_id' => 111],
        ]);

        $this->mock(JiraService::class)
            ->shouldReceive('getUsers')
            ->never();
        $this->mock(TelegramMessenger::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text, array $extra): bool => str_contains($text, 'Alice')
                // The outcome ends the link flow, so the main menu comes back.
                && str_contains($extra['reply_markup'] ?? '', '"keyboard"'));

        app(AccountLinkService::class)->linkAccount(1, 'other@example.com', 111);
    }

    public function test_existing_link_is_not_modified_by_refused_attempt(): void
    {
        $this->fakeJiraUserLookup([
            ['jira_account_id' => 'alice', 'display_name' => 'Alice', 'email' => 'alice@example.com', 'telegram_admin_id' => 111],
        ]);

        $this->mock(JiraService::class)
            ->shouldReceive('getUsers')
            ->never();
        $this->mock(TelegramMessenger::class)
            ->shouldReceive('sendMessage')
            ->once();

        app(AccountLinkService::class)->linkAccount(1, 'other@example.com', 111);
    }

    private function fakeJiraUserLookup(array $rows): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar = new MySqlGrammar($connection));
        $connection->shouldReceive('getPostProcessor')->andReturn(new MySqlProcessor);
        $connection->shouldReceive('getDatabaseName')->andReturn('testing');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getName')->andReturn('mysql');
        $connection->shouldReceive('query')->andReturn(
            new BaseBuilder($connection, $grammar, new MySqlProcessor)
        );
        $connection->shouldReceive('select')->andReturn($rows);
        $connection->shouldReceive('update')->never();
        $connection->shouldReceive('insert')->never();

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        JiraUser::setConnectionResolver($resolver);
    }
}
