<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Enums\TaskOwner;
use App\Services\Jira\TaskOwnerService;
use Tests\TestCase;

class TaskOwnerServiceTest extends TestCase
{
    private const FIELD_NAME = 'Owner';

    private const FIELD_ID = 'customfield_10050';

    public function test_resolve_owner_for_status(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $service = new TaskOwnerService($jira);

        // Mappings from JIRA_STATUS_OWNER_MAP (see phpunit.xml)
        $this->assertSame(TaskOwner::L1, $service->resolveOwnerForStatus('OPEN'));
        $this->assertSame(TaskOwner::L1, $service->resolveOwnerForStatus('IN PROGRESS'));
        $this->assertSame(TaskOwner::L2, $service->resolveOwnerForStatus('ESCALATED'));
        $this->assertSame(TaskOwner::Reporter, $service->resolveOwnerForStatus('WAITING FOR REPORTER'));
        $this->assertSame(TaskOwner::Reporter, $service->resolveOwnerForStatus('DONE'));

        // Case insensitivity and whitespace tolerance
        $this->assertSame(TaskOwner::L1, $service->resolveOwnerForStatus('open'));
        $this->assertSame(TaskOwner::L1, $service->resolveOwnerForStatus('  In Progress  '));
        $this->assertSame(TaskOwner::L2, $service->resolveOwnerForStatus('Escalated'));
        $this->assertSame(TaskOwner::Reporter, $service->resolveOwnerForStatus('done'));

        // Unmapped statuses
        $this->assertNull($service->resolveOwnerForStatus('NonExistentStatus'));
        $this->assertNull($service->resolveOwnerForStatus(''));
    }

    public function test_get_owner_field_id_and_has_owner_field(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')
            ->with(self::FIELD_NAME)
            ->andReturn(self::FIELD_ID, null);

        $service = new TaskOwnerService($jira);

        $this->assertSame(self::FIELD_ID, $service->getOwnerFieldId());
        $this->assertFalse($service->hasOwnerField());
    }

    public function test_set_owner_success(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturn(self::FIELD_ID);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', self::FIELD_ID, 'L1')->andReturn('opt-101');
        $jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', self::FIELD_ID, ['id' => 'opt-101']);

        $service = new TaskOwnerService($jira);

        $this->assertTrue($service->setOwner('DEMO-1', TaskOwner::L1));
    }

    public function test_set_owner_skipped_when_field_not_defined(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturnNull();
        $jira->shouldNotReceive('optionIdForCode');
        $jira->shouldNotReceive('updateIssueField');

        $service = new TaskOwnerService($jira);

        $this->assertFalse($service->setOwner('DEMO-1', TaskOwner::L1));
    }

    public function test_set_owner_skipped_when_option_not_found(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturn(self::FIELD_ID);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', self::FIELD_ID, 'L1')->andReturnNull();
        $jira->shouldNotReceive('updateIssueField');

        $service = new TaskOwnerService($jira);

        $this->assertFalse($service->setOwner('DEMO-1', TaskOwner::L1));
    }

    public function test_sync_owner_for_status(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturn(self::FIELD_ID);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', self::FIELD_ID, 'L2')->andReturn('opt-102');
        $jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', self::FIELD_ID, ['id' => 'opt-102']);

        $service = new TaskOwnerService($jira);

        $result = $service->syncOwnerForStatus('DEMO-1', 'ESCALATED');
        $this->assertSame(TaskOwner::L2, $result);

        // Unmapped status does not trigger update
        $unmappedResult = $service->syncOwnerForStatus('DEMO-1', 'UNKNOWN_STATUS');
        $this->assertNull($unmappedResult);
    }

    public function test_sync_owner_for_issue_with_data(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturn(self::FIELD_ID);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', self::FIELD_ID, 'Reporter')->andReturn('opt-rep');
        $jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', self::FIELD_ID, ['id' => 'opt-rep']);

        $service = new TaskOwnerService($jira);

        $issueData = [
            'key' => 'DEMO-1',
            'fields' => [
                'status' => ['name' => 'CLOSED'],
            ],
        ];

        $result = $service->syncOwnerForIssue('DEMO-1', $issueData);
        $this->assertSame(TaskOwner::Reporter, $result);
    }

    public function test_sync_owner_for_issue_fetches_when_data_not_provided(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn([
            'key' => 'DEMO-1',
            'fields' => [
                'status' => ['name' => 'OPEN'],
            ],
        ]);
        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturn(self::FIELD_ID);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', self::FIELD_ID, 'L1')->andReturn('opt-l1');
        $jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', self::FIELD_ID, ['id' => 'opt-l1']);

        $service = new TaskOwnerService($jira);

        $result = $service->syncOwnerForIssue('DEMO-1');
        $this->assertSame(TaskOwner::L1, $result);
    }

    public function test_sync_owners_batch(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getIssues')->once()->andReturn([
            'issues' => [
                [
                    'key' => 'DEMO-1',
                    'fields' => [
                        'status' => ['name' => 'OPEN'],
                        self::FIELD_ID => null,
                    ],
                ],
                [
                    'key' => 'DEMO-2',
                    'fields' => [
                        'status' => ['name' => 'CLOSED'],
                        self::FIELD_ID => ['value' => 'Reporter'], // already up to date
                    ],
                ],
                [
                    'key' => 'DEMO-3',
                    'fields' => [
                        'status' => ['name' => 'UNMAPPED_STATUS'],
                    ],
                ],
            ],
            'total' => 3,
        ]);

        $jira->shouldReceive('customFieldId')->with(self::FIELD_NAME)->andReturn(self::FIELD_ID);
        $jira->shouldReceive('optionIdForCode')->with('DEMO-1', self::FIELD_ID, 'L1')->andReturn('opt-l1');
        $jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', self::FIELD_ID, ['id' => 'opt-l1']);

        $service = new TaskOwnerService($jira);
        $summary = $service->syncOwnersBatch();

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(2, $summary['skipped']);
        $this->assertEmpty($summary['errors']);
    }
}
