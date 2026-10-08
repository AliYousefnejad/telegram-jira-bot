<?php

namespace Tests\Concerns;

use App\Models\JiraUser;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Mockery;

/**
 * Serves JiraUser lookups (e.g. the username sync on every update) from
 * fixed rows instead of a database.
 */
trait FakesJiraUserLookup
{
    /** @param  array<int, array<string, mixed>>  $rows */
    protected function fakeJiraUserLookup(array $rows = []): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar = new MySqlGrammar($connection));
        $connection->shouldReceive('getPostProcessor')->andReturn(new MySqlProcessor);
        $connection->shouldReceive('getDatabaseName')->andReturn('testing');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getName')->andReturn('mysql');
        $connection->shouldReceive('query')->andReturn(new BaseBuilder($connection, $grammar, new MySqlProcessor));
        $connection->shouldReceive('select')->andReturn($rows);

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        JiraUser::setConnectionResolver($resolver);
    }
}
