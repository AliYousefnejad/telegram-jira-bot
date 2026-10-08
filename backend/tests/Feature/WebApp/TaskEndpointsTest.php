<?php

namespace Tests\Feature\WebApp;

use App\Models\JiraUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class TaskEndpointsTest extends WebAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->linkAdmin();
    }

    #[Test]
    public function statuses_lists_every_board_status_with_counts_and_owner(): void
    {
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('getIssues')->once()->with('project = "TS"', 200)->andReturn(['total' => 4, 'issues' => [
                $this->issue('TS-1'), $this->issue('TS-2'), $this->issue('TS-3', status: 'Under Triage'), $this->issue('TS-4', status: 'Closed'),
            ]]);
            $jira->shouldReceive('getProjectBoard')->with('TS')->andReturn(7);
            $jira->shouldReceive('getBoardConfiguration')->with(7)->andReturn(['status_rows' => [
                ['id' => '1', 'name' => 'Raised'], ['id' => '2', 'name' => 'Under Triage'], ['id' => '3', 'name' => 'Update pending'], ['id' => '4', 'name' => 'Closed'],
            ]]);
        });

        $this->getJson('/api/webapp/statuses', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson(['open' => 3, 'statuses' => [
                ['id' => '1', 'name' => 'Raised', 'count' => 2, 'owner' => 'L1'],
                ['id' => '2', 'name' => 'Under Triage', 'count' => 1, 'owner' => 'L1'],
                ['id' => '3', 'name' => 'Update pending', 'count' => 0, 'owner' => 'L2'],
                ['id' => '4', 'name' => 'Closed', 'count' => 1, 'owner' => 'Reporter'],
            ]]);
    }

    #[Test]
    public function tasks_are_sorted_by_severity_then_impact_then_newest(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssues')
            ->withArgs(fn (string $jql) => $jql === 'project = "TS" AND statusCategory != Done ORDER BY updated DESC')
            ->andReturn(['total' => 4, 'issues' => [
                $this->issue('TS-1', 'S3', 'I1'),
                $this->issue('TS-2', 'S1', 'I3', updated: '2026-10-01T09:00:00.000+0330'),
                $this->issue('TS-3', 'S1', 'I3', updated: '2026-10-02T09:00:00.000+0330'),
                $this->issue('TS-4', 'S1', 'I1'),
            ]]));

        $keys = array_column($this->getJson('/api/webapp/tasks', $this->tmaHeaders($this->telegramUser()))->assertOk()->json('tasks'), 'key');

        $this->assertSame(['TS-4', 'TS-3', 'TS-2', 'TS-1'], $keys);
    }

    #[Test]
    public function a_status_filter_narrows_the_query_and_is_escaped(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssues')
            ->once()
            ->withArgs(fn (string $jql) => $jql === 'project = "TS" AND status = "Odd \\"name\\"" ORDER BY updated DESC')
            ->andReturn(['total' => 0, 'issues' => []]));

        $this->getJson('/api/webapp/tasks?status='.urlencode('Odd "name"'), $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson(['tasks' => []]);
    }

    #[Test]
    public function assignees_carry_their_linked_telegram_username(): void
    {
        JiraUser::factory()->create(['jira_account_id' => 'sara', 'telegram_username' => 'sara_tg']);
        JiraUser::factory()->unlinked()->create(['jira_account_id' => 'old.link', 'telegram_username' => 'stale']);

        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssues')->andReturn(['total' => 3, 'issues' => [
            $this->issue('TS-1', 'S1', assignee: ['name' => 'sara', 'displayName' => 'Sara']),
            $this->issue('TS-2', 'S2', assignee: ['name' => 'old.link', 'displayName' => 'Old Link']),
            $this->issue('TS-3', 'S3'),
        ]]));

        $tasks = $this->getJson('/api/webapp/tasks', $this->tmaHeaders($this->telegramUser()))->assertOk()->json('tasks');

        $this->assertSame(['name' => 'Sara', 'telegram' => '@sara_tg'], $tasks[0]['assignee']);
        $this->assertSame(['name' => 'Old Link', 'telegram' => null], $tasks[1]['assignee']);
        $this->assertNull($tasks[2]['assignee']);
        $this->assertSame('S1', $tasks[0]['severity']);
        $this->assertSame('1405/06/15 02:32', $tasks[0]['created_jalali']);
    }

    #[Test]
    public function task_detail_includes_description_and_labels(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->with('TS-12')->andReturn($this->issue('TS-12', 'S2', 'I4', 'Under Triage')));

        $this->getJson('/api/webapp/tasks/ts-12', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJson(['task' => [
                'key' => 'TS-12', 'status' => 'Under Triage', 'severity' => 'S2', 'severity_label' => 'S2 – High',
                'impact' => 'I4', 'description' => 'Description of TS-12', 'type' => 'Bug',
            ]]);
    }

    #[Test]
    public function an_unknown_task_is_a_404(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->with('TS-404')->andReturnNull());

        $this->getJson('/api/webapp/tasks/TS-404', $this->tmaHeaders($this->telegramUser()))->assertNotFound();
    }

    #[Test]
    #[DataProvider('foreignOrMalformedKeys')]
    public function keys_outside_the_project_are_refused_without_asking_jira(string $key): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldNotReceive('getIssue'));

        $this->getJson('/api/webapp/tasks/'.rawurlencode($key), $this->tmaHeaders($this->telegramUser()))
            ->assertUnprocessable()
            ->assertJson(['error' => 'invalid_key']);
    }

    #[Test]
    public function changing_the_default_project_moves_the_app_to_that_board(): void
    {
        config(['services.jira.default_project' => 'HR']);
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('getIssues')->withArgs(fn (string $jql) => str_starts_with($jql, 'project = "HR"'))->andReturn(['total' => 0, 'issues' => []]);
            $jira->shouldReceive('getIssue')->with('HR-5')->andReturn($this->issue('HR-5'));
        });

        $this->getJson('/api/webapp/tasks', $this->tmaHeaders($this->telegramUser()))->assertOk();
        $this->getJson('/api/webapp/tasks/HR-5', $this->tmaHeaders($this->telegramUser()))->assertOk();
        $this->getJson('/api/webapp/tasks/TS-5', $this->tmaHeaders($this->telegramUser()))->assertUnprocessable();
    }

    /** @return array<string, array{string}> */
    public static function foreignOrMalformedKeys(): array
    {
        return [
            'another project' => ['HR-5'],
            'no number' => ['TS-x'],
            'jql injection' => ['TS-1 OR project'],
            'no dash' => ['TS5'],
        ];
    }
}
