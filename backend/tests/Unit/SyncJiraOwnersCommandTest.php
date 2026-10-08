<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use Tests\TestCase;

class SyncJiraOwnersCommandTest extends TestCase
{
    public function test_sync_command_skips_when_owner_field_missing(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with('Owner')->andReturnNull();

        $this->artisan('jira:sync-owners')
            ->expectsOutputToContain("Owner custom field ('Owner') was not found on Jira. Skipping.")
            ->assertExitCode(0);
    }

    public function test_sync_command_runs_successfully(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with('Owner')->andReturn('customfield_10050');
        $jira->shouldReceive('getIssues')->once()->andReturn([
            'issues' => [
                [
                    'key' => 'DEMO-1',
                    'fields' => [
                        'status' => ['name' => 'OPEN'],
                        'customfield_10050' => null,
                    ],
                ],
            ],
            'total' => 1,
        ]);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', 'customfield_10050', 'L1')->andReturn('opt-l1');
        $jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', 'customfield_10050', ['id' => 'opt-l1']);

        $this->artisan('jira:sync-owners')
            ->expectsOutputToContain('Starting Jira issue owner synchronization...')
            ->expectsOutputToContain('Jira issue owner synchronization completed successfully.')
            ->assertExitCode(0);
    }
}
