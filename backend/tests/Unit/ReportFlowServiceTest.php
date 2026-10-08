<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\ReportType;
use App\Models\JiraUser;
use App\Resources\ReportsMessageResource;
use App\Services\Telegram\Reports\ReportFlowService;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Mockery\MockInterface;
use Tests\TestCase;

class ReportFlowServiceTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    private JiraServiceInterface|MockInterface $jira;

    private TelegramMessengerInterface|MockInterface $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jira = $this->mock(JiraServiceInterface::class);
        $this->messenger = $this->mock(TelegramMessengerInterface::class);
    }

    public function test_a_user_bug_report_is_created_with_user_info_and_reporter(): void
    {
        $this->fakeLinkedReporter('jsmith');

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $summary, string $type, string $description, $assignee, $reporter, array $extraFields = []): bool {
                return $project === 'DEMO'
                    && $type === 'Bug'
                    && $summary === ReportType::UserBug->jiraLabel().': Ali'
                    && ! str_contains($summary, ReportType::UserBug->badge())
                    && str_contains($description, 'Full problem description here')
                    && str_contains($description, 'Ali')
                    && $reporter === 'jsmith';
            })
            ->andReturn(['key' => 'DEMO-9']);

        $this->jira->shouldReceive('getIssue')->with('DEMO-9')->andReturn([
            'key' => 'DEMO-9',
            'fields' => [
                'summary' => 's',
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'John Smith'],
            ],
        ]);

        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'demo',
            'type' => 'user_bug',
            'user_name' => 'Ali',
            'description' => 'Full problem description here',
        ], self::USER_ID);

        // The reporter is the linked Jira account of the admin.
        $this->assertTrue(true);
    }

    public function test_user_bug_description_lists_each_non_empty_user_field(): void
    {
        app()->setLocale('fa');

        $this->fakeLinkedReporter(null);

        $body = null;

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $summary, string $type, string $description, $assignee, $reporter, array $extraFields) use (&$body): bool {
                $body = $description;

                return true;
            })
            ->andReturn(['key' => 'DEMO-13']);

        $this->jira->shouldReceive('getIssue')->andReturn(null);
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'user_bug',
            'user_name' => 'ali',
            'user_identifier' => 'ID-1',
            'user_mobile' => '0912',
            'description' => 'A long enough description of the bug',
        ], self::USER_ID);

        $this->assertIsString($body);
        $this->assertStringContainsString(
            '* '.__('reports.jira.user_name_label').': "ali"',
            $body,
        );
        $this->assertStringContainsString(
            '* '.__('reports.jira.user_identifier_label').': "ID-1"',
            $body,
        );
        $this->assertStringContainsString(
            '* '.__('reports.jira.user_mobile_label').': "0912"',
            $body,
        );
    }

    public function test_user_bug_description_omits_empty_user_fields(): void
    {
        app()->setLocale('fa');

        $this->fakeLinkedReporter(null);

        $body = null;

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $summary, string $type, string $description, $assignee, $reporter, array $extraFields) use (&$body): bool {
                $body = $description;

                return true;
            })
            ->andReturn(['key' => 'DEMO-14']);

        $this->jira->shouldReceive('getIssue')->andReturn(null);
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'user_bug',
            'user_name' => 'ali',
            'description' => 'A long enough description of the bug',
        ], self::USER_ID);

        $this->assertIsString($body);
        $this->assertStringContainsString(
            '* '.__('reports.jira.user_name_label').': "ali"',
            $body,
        );
        $this->assertStringNotContainsString(__('reports.jira.user_identifier_label'), $body);
        $this->assertStringNotContainsString(__('reports.jira.user_mobile_label'), $body);
    }

    public function test_user_bug_summary_includes_all_user_fields(): void
    {
        $this->fakeLinkedReporter(null);

        $summary = null;

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $captured, string $type, string $description, $assignee, $reporter, array $extraFields) use (&$summary): bool {
                $summary = $captured;

                return true;
            })
            ->andReturn(['key' => 'DEMO-15']);

        $this->jira->shouldReceive('getIssue')->andReturn(null);
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'user_bug',
            'user_name' => 'ali',
            'user_identifier' => 'ID-1',
            'user_mobile' => '0912',
            'description' => 'A long enough description of the bug',
        ], self::USER_ID);

        $this->assertIsString($summary);
        $this->assertStringStartsWith(ReportType::UserBug->jiraLabel().': ', $summary);
        $this->assertStringContainsString('ali', $summary);
        $this->assertStringContainsString('ID-1', $summary);
        $this->assertStringContainsString('0912', $summary);
    }

    public function test_user_bug_summary_omits_empty_user_fields(): void
    {
        $this->fakeLinkedReporter(null);

        $summary = null;

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $captured, string $type, string $description, $assignee, $reporter, array $extraFields) use (&$summary): bool {
                $summary = $captured;

                return true;
            })
            ->andReturn(['key' => 'DEMO-16']);

        $this->jira->shouldReceive('getIssue')->andReturn(null);
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'user_bug',
            'description' => 'A long enough description of the bug',
        ], self::USER_ID);

        $this->assertIsString($summary);
        $this->assertSame(ReportType::UserBug->jiraLabel(), $summary);
    }

    public function test_missing_bug_issue_type_falls_back_to_task(): void
    {
        $this->fakeLinkedReporter(null);

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->with('DEMO', \Mockery::pattern('/./'), 'Bug', \Mockery::any(), null, null, [])
            ->andThrow(new \RuntimeException('Jira API returned HTTP 400'));

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $summary, string $type, string $description, $assignee, $reporter, array $extraFields): bool {
                return $project === 'DEMO' && $type === 'Task' && $extraFields === [];
            })
            ->andReturn(['key' => 'DEMO-10']);

        // The confirmation fetches the created issue; absent response falls
        // back to the creation payload.
        $this->jira->shouldReceive('getIssue')->andReturn(null);

        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'user_bug',
            'user_name' => 'Ali',
            'description' => 'A long enough description of the bug',
        ], self::USER_ID);
    }

    public function test_report_payload_includes_severity_and_impact_when_fields_exist(): void
    {
        $this->fakeLinkedReporter('jsmith');

        $this->jira->shouldReceive('customFieldId')->with('Severity')->andReturn('customfield_1');
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn('customfield_2');

        $payload = null;

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $summary, string $type, string $description, $assignee, $reporter, array $extraFields) use (&$payload): bool {
                $payload = $extraFields;

                return $project === 'DEMO' && $type === 'Task';
            })
            ->andReturn(['key' => 'DEMO-11']);

        // Select fields are applied after creation, resolved via editmeta.
        $this->jira->shouldReceive('optionIdForCode')
            ->once()
            ->with('DEMO-11', 'customfield_2', 'I2')
            ->andReturn('10205');
        $this->jira->shouldReceive('optionIdForCode')
            ->once()
            ->with('DEMO-11', 'customfield_1', 'S2')
            ->andReturn('10201');

        // Impact is multi-select (array payload), severity single-select.
        $this->jira->shouldReceive('updateIssueField')
            ->once()
            ->with('DEMO-11', 'customfield_2', [['id' => '10205']]);
        $this->jira->shouldReceive('updateIssueField')
            ->once()
            ->with('DEMO-11', 'customfield_1', ['id' => '10201']);

        $this->jira->shouldReceive('getIssue')->andReturn(null);
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'admin_panel_problem',
            'description' => 'A complete description of the panel problem',
            'severity' => 'S2',
            'impact' => 'I2',
        ], self::USER_ID);

        $this->assertSame([], $payload);
    }

    public function test_report_payload_omits_custom_field_values_when_fields_are_missing(): void
    {
        $this->fakeLinkedReporter(null);

        $this->jira->shouldReceive('customFieldId')->with('Severity')->andReturn(null);
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn(null);

        $payload = null;

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->withArgs(function (string $project, string $summary, string $type, string $description, $assignee, $reporter, array $extraFields) use (&$payload): bool {
                $payload = $extraFields;

                return true;
            })
            ->andReturn(['key' => 'DEMO-12']);

        $this->jira->shouldReceive('getIssue')->andReturn(null);
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'force_majeure',
            'description' => 'A complete description of the force majeure',
        ], self::USER_ID);

        $this->assertSame([], $payload);
    }

    public function test_unknown_report_types_are_rejected_without_jira_calls(): void
    {
        $this->jira->shouldNotReceive('createIssue');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, ReportsMessageResource::invalidType(), \Mockery::type('array'));

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, ['type' => 'nonsense'], self::USER_ID);
    }

    public function test_too_short_descriptions_are_rejected_without_jira_calls(): void
    {
        $this->jira->shouldNotReceive('createIssue');
        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'admin_panel_problem',
            'description' => 'short',
        ], self::USER_ID);
    }

    public function test_finalize_report_restores_main_menu_with_a_timeline_button(): void
    {
        $this->fakeLinkedReporter(null);

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->andReturn(['key' => 'DEMO-25']);

        $this->jira->shouldReceive('getIssue')->with('DEMO-25')->andReturn([
            'key' => 'DEMO-25',
            'fields' => [
                'summary' => 'Some issue',
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'Reporter'],
            ],
        ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                $rows = self::replyRows($extra, self::USER_ID);
                $labels = array_column(array_merge(...$rows), 'text');

                return ($rows[0][0]['callback_data'] ?? '') === 'timeline_DEMO-25'
                    && $rows[0][0]['text'] === __('keyboard.button.task_timeline', ['key' => 'DEMO-25'])
                    && in_array(__('keyboard.button.register_report'), $labels, true);
            });

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'admin_panel_problem',
            'description' => 'Valid description here',
        ], self::USER_ID);
    }

    public function test_report_flow_applies_category_field(): void
    {
        $this->fakeLinkedReporter(null);

        $this->jira->shouldReceive('createIssue')
            ->once()
            ->andReturn(['key' => 'DEMO-20']);

        $this->jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturn('customfield_10090');
        $this->jira->shouldReceive('updateIssueField')
            ->once()
            ->with('DEMO-20', 'customfield_10090', ['id' => '55']);

        $this->jira->shouldReceive('getIssue')->with('DEMO-20')->andReturn([
            'key' => 'DEMO-20',
            'fields' => [
                'summary' => 'Some issue',
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'Reporter'],
                'customfield_10090' => ['value' => 'Payment'],
            ],
        ]);

        $this->messenger->shouldReceive('sendMessage')->once();

        app(ReportFlowService::class)->finalizeReport(self::CHAT_ID, [
            'project' => 'DEMO',
            'type' => 'user_bug',
            'user_name' => 'Ali',
            'description' => 'A complete description of the bug',
            'category' => '55',
        ], self::USER_ID);
    }

    /**
     * Same fake-connection pattern as AccountLinkServiceTest: the reporter
     * lookup reads the linked jira_users row without a real database.
     */
    private function fakeLinkedReporter(?string $accountId): void
    {
        $rows = $accountId === null ? [] : [[
            'jira_account_id' => $accountId,
            'display_name' => 'John Smith',
            'telegram_admin_id' => self::USER_ID,
        ]];

        $connection = \Mockery::mock(Connection::class);
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

        $resolver = \Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        JiraUser::setConnectionResolver($resolver);
    }
}
