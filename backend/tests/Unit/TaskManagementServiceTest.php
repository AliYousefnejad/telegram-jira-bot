<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Enums\EditAction;
use App\Enums\FlowCommand;
use App\Enums\ReportType;
use App\Resources\FlowMessageResource;
use App\Resources\ReportsMessageResource;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\InteractiveMessages;
use App\Services\Telegram\ReplyKeyboardService;
use App\Services\Telegram\Reports\TaskManagementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class TaskManagementServiceTest extends TestCase
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
        $this->mock(AccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isBotAdmin')->byDefault()->andReturnTrue();
            $mock->shouldReceive('isUserLinked')->byDefault()->andReturnTrue();
            $mock->shouldReceive('linkedJiraDisplayName')->byDefault()->andReturn('Ali Admin');
        });
    }

    protected function tearDown(): void
    {
        Cache::forget('rpt_ctx_'.self::USER_ID);
        Cache::forget('rpt_statuses_'.self::USER_ID);
        Cache::forget('rpt_desc_'.self::USER_ID);
        Cache::forget('rpt_desc_ui_'.self::USER_ID);
        Cache::forget('rpt_comment_'.self::USER_ID);
        Cache::forget('interactive_msgs_'.self::USER_ID);
        app(ConversationManager::class)->clear(self::USER_ID);

        parent::tearDown();
    }

    public function test_non_admins_are_blocked_before_any_jira_call(): void
    {
        $this->mock(AccessService::class)->shouldReceive('isBotAdmin')->andReturnFalse();
        $this->jira->shouldNotReceive('getIssues');

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, ReportsMessageResource::adminOnly(), []);

        $this->handleCallback(CallbackAction::RptMenu->value.'tasks');
    }

    public function test_the_reports_menu_buttons_reach_their_flows(): void
    {
        // Covered at the UpdateProcessor level (see UpdateProcessorReportsMenuTest):
        // this test only pins that rptmenu_ callbacks are NOT silently dropped by
        // the service dispatcher, which must warn about unknown payloads.
        Log::shouldReceive('warning')->with('Unhandled report callback payload', Mockery::on(
            fn (array $context): bool => ($context['data'] ?? '') === 'rptmenu_tasks'
        ));
        $this->messenger->shouldReceive('sendMessage')->never();
        $this->jira->shouldNotReceive('getIssues');

        $this->handleCallback(CallbackAction::RptMenu->value.'tasks');
    }

    public function test_project_pick_shows_board_statuses_and_the_all_open_shortcut(): void
    {
        // Board-driven source: same statuses the Change Status flow offers,
        // including statuses that currently hold zero tasks.
        $this->jira->shouldReceive('getProjectBoard')->with('DEMO')->andReturn(7);
        $this->jira->shouldReceive('getBoardConfiguration')->with(7)->andReturn([
            'statuses' => ['Raised', 'Update pending'],
            'status_rows' => [
                ['id' => '10306', 'name' => 'Raised'],
                ['id' => '10800', 'name' => 'Update pending'],
            ],
            'project_key' => 'DEMO',
        ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $data = [];
                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        $data[] = $button['callback_data'];
                    }
                }

                // Every Jira status is offered (zero-task ones included)
                // plus the All Open shortcut.
                return in_array(CallbackAction::RptFilter->value.'10306', $data, true)
                    && in_array(CallbackAction::RptFilter->value.'10800', $data, true)
                    && in_array(CallbackAction::RptFilter->value.'open', $data, true);
            });

        // Project picked first
        $this->handleCallback(CallbackAction::RptProject->value.'DEMO');
    }

    public function test_open_filter_lists_tasks_without_a_status_clause(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['project' => 'DEMO', 'status_id' => null, 'status_name' => null, 'page' => 1], 30);
        Cache::put('rpt_statuses_'.self::USER_ID, ['10306' => 'Raised'], 30);

        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssues')
            ->once()
            ->withArgs(function (...$args): bool {
                $jql = (string) ($args[0] ?? '');

                return str_contains($jql, 'statusCategory != Done')
                    && ! str_contains($jql, 'status =');
            })
            ->andReturn(['issues' => [$this->issue('DEMO-1', '10306', 'Raised')], 'hasMore' => false]);

        $this->messenger->shouldReceive('sendMessage')->once();

        $this->handleCallback(CallbackAction::RptFilter->value.'open');
    }

    public function test_filter_button_requeries_with_the_selected_status(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['project' => 'DEMO', 'status_id' => null, 'status_name' => null, 'page' => 1], 30);
        Cache::put('rpt_statuses_'.self::USER_ID, ['10306' => 'Raised', '10800' => 'Update pending'], 30);

        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssues')
            ->once()
            ->withArgs(function (string $jql): bool {
                return ! str_contains($jql, 'statusCategory')
                    && str_contains($jql, 'status = "Raised"');
            })
            ->andReturn(['issues' => [$this->issue('DEMO-1', '10306', 'Raised')], 'hasMore' => false]);

        $this->messenger->shouldReceive('sendMessage')->once();

        $this->handleCallback(CallbackAction::RptFilter->value.'10306');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('Raised', $context['status_name']);
    }

    public function test_closed_status_filter_lists_done_category_tasks(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['project' => 'DEMO', 'status_id' => null, 'status_name' => null, 'page' => 1], 30);
        Cache::put('rpt_statuses_'.self::USER_ID, ['10500' => 'Closed'], 30);

        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssues')
            ->once()
            ->withArgs(function (string $jql): bool {
                return ! str_contains($jql, 'statusCategory')
                    && str_contains($jql, 'status = "Closed"');
            })
            ->andReturn([
                'issues' => [
                    ['key' => 'DEMO-7', 'fields' => ['summary' => 'Closed report issue', 'status' => ['id' => '10500', 'name' => 'Closed']]],
                ],
                'hasMore' => false,
            ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $texts = [];

                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        $texts[] = $button['text'] ?? '';
                    }
                }

                return in_array('• DEMO-7 [Closed] — Closed report issue', $texts, true);
            });

        $this->handleCallback(CallbackAction::RptFilter->value.'10500');
    }

    public function test_status_selection_lists_tasks_as_buttons(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'project' => 'DEMO',
            'status_id' => null,
            'status_name' => null,
            'page' => 1,
        ], 30);

        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssues')
            ->once()
            ->andReturn([
                'issues' => [$this->issue('DEMO-1', '10306', 'Raised'), $this->issue('DEMO-9', '10306', 'Raised')],
                'hasMore' => false,
            ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $firstColumn = array_map(fn (array $row): string => $row[0]['callback_data'] ?? '', $rows);

                return in_array(CallbackAction::RptTask->value.'DEMO-1', $firstColumn, true)
                    && in_array(CallbackAction::RptTask->value.'DEMO-9', $firstColumn, true);
            });

        $this->handleCallback(CallbackAction::RptFilter->value.'open');
    }

    public function test_report_tasks_show_status_and_user_ref_on_list_rows(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['project' => 'DEMO', 'status_id' => null, 'status_name' => null, 'page' => 1], 30);

        $report = ReportType::UserBug;

        // Legacy (emoji) and new (plain) Jira summaries are both recognised.
        $legacySummary = $report->label().': 09391234567';
        $plainSummary = $report->jiraLabel().': 09987654321';

        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssues')
            ->once()
            ->andReturn([
                'issues' => [
                    ['key' => 'DEMO-1', 'fields' => ['summary' => $legacySummary, 'status' => ['id' => '10306', 'name' => 'Raised']]],
                    ['key' => 'DEMO-2', 'fields' => ['summary' => $plainSummary, 'status' => ['id' => '10306', 'name' => 'Raised']]],
                    ['key' => 'DEMO-3', 'fields' => ['summary' => 'Plain task', 'status' => ['id' => '10306', 'name' => 'Raised']]],
                ],
                'hasMore' => false,
            ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $texts = [];

                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        $texts[] = $button['text'] ?? '';
                    }
                }

                $report = ReportType::UserBug;

                return in_array('• DEMO-1 [Raised] '.$report->telegramType().' 09391234567', $texts, true)
                    && in_array('• DEMO-2 [Raised] '.$report->telegramType().' 09987654321', $texts, true)
                    && ! in_array($report->label().': 09391234567', $texts, true)
                    && ! in_array($report->jiraLabel().': 09987654321', $texts, true)
                    && in_array('• DEMO-3 [Raised] — Plain task', $texts, true)
                    && ! in_array($report->badge().' DEMO-1 [Raised] 09391234567', $texts, true);
            });

        $this->handleCallback(CallbackAction::RptFilter->value.'open');
    }

    public function test_task_list_is_sorted_by_severity_then_impact(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['project' => 'DEMO', 'status_id' => null, 'status_name' => null, 'page' => 1], 30);
        Cache::put('rpt_statuses_'.self::USER_ID, ['10306' => 'Raised'], 30);

        $this->jira->shouldReceive('customFieldId')->with('Severity')->andReturn('customfield_1');
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn('customfield_2');
        $this->jira->shouldReceive('getIssues')
            ->once()
            ->andReturn([
                'issues' => [
                    $this->issue('DEMO-A', '10306', 'Raised', 'S3 - Medium', 1),
                    $this->issue('DEMO-B', '10306', 'Raised', 'S1 - Critical', 3),
                    $this->issue('DEMO-C', '10306', 'Raised', 'S1 - Critical'),
                    $this->issue('DEMO-D', '10306', 'Raised'),
                ],
                'hasMore' => false,
            ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $order = array_map(fn (array $row): string => $row[0]['callback_data'] ?? '', $rows);

                // Most critical first: S1/I3, then S1 without impact, then
                // S3/I1, and unset fields sink to the bottom.
                return array_slice($order, 0, 4) === [
                    CallbackAction::RptTask->value.'DEMO-B',
                    CallbackAction::RptTask->value.'DEMO-C',
                    CallbackAction::RptTask->value.'DEMO-A',
                    CallbackAction::RptTask->value.'DEMO-D',
                ];
            });

        $this->handleCallback(CallbackAction::RptFilter->value.'open');
    }

    public function test_edit_menu_hides_impact_and_severity_until_the_fields_exist(): void
    {
        $this->mock(AccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isBotAdmin')->andReturnTrue();
            $mock->shouldReceive('isUserLinked')->andReturnTrue();
        });

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        $api->shouldReceive('deleteMessages')->andReturnTrue();
        Telegram::swap($api);

        $this->jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn(null);
        $this->jira->shouldReceive('customFieldId')->with('Severity')->andReturn(null);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$a): bool {
                $markup = self::replyMarkupWithActions($a[2] ?? [], self::USER_ID);

                // Impact/severity hidden until the fields exist; priority is
                // disabled; every remaining action sits on its own row.
                return ! str_contains($markup, 'rptact_impact_')
                    && ! str_contains($markup, 'rptact_severity_')
                    && ! str_contains($markup, 'rptact_priority_')
                    && str_contains($markup, 'rptact_status_DEMO-1')
                    && str_contains($markup, 'rptact_description_DEMO-1')
                    && str_contains($markup, 'rptact_comment_DEMO-1');
            });

        app(TaskManagementService::class)->handleCallback($this->callbackQuery(CallbackAction::RptTask->value.'DEMO-1'));

        $this->messenger->shouldHaveReceived('sendMessage')->once();
    }

    public function test_status_options_come_from_the_board_sorted_by_column_order(): void
    {
        $this->jira->shouldReceive('getProjectBoard')->with('TS')->andReturn(7);
        $this->jira->shouldReceive('getBoardConfiguration')->with(7)->andReturn([
            'statuses' => ['Raised', 'Verified'],
            'status_rows' => [
                ['id' => '10306', 'name' => 'Raised'],
                ['id' => '10801', 'name' => 'Verified'],
            ],
            'project_key' => 'DEMO',
        ]);
        // Jira returns the transitions scrambled; the board order must win.
        $this->jira->shouldReceive('getIssueTransitions')
            ->with('TS-4')
            ->andReturn([
                ['id' => '31', 'name' => 'Verify it', 'to' => ['name' => 'verified']],
                ['id' => '30', 'name' => 'Raise it', 'to' => ['name' => 'Raised']],
            ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $data = array_map(fn (array $row): string => $row[0]['callback_data'] ?? '', $rows);

                // Board column order: Raised before Verified; every button
                // is a flat object on its own row; cancel row appended last.
                return $data === [
                    CallbackAction::RptSet->value.'status_TS-4_30',
                    CallbackAction::RptSet->value.'status_TS-4_31',
                    CallbackAction::RptCancel->value,
                ];
            });

        $this->handleCallback(CallbackAction::RptAct->value.'status_TS-4');
    }

    public function test_setting_a_status_stages_it_until_confirm(): void
    {
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->jira->shouldReceive('getIssueTransitions')
            ->with('DEMO-1')
            ->once()
            ->andReturn([['id' => '31', 'name' => 'Close it', 'to' => ['name' => 'Closed']]]);

        // Staging: no Jira write yet, the staged edit menu is rendered instead.
        $this->jira->shouldNotReceive('transitionIssue');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptconfirm_DEMO-1'));

        $this->handleCallback(CallbackAction::RptSet->value.'status_DEMO-1_31');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('DEMO-1', $context['pending_task']);
        $this->assertSame(['value' => '31', 'label' => 'Closed'], $context['pending']['status']);

        // Confirm: applies via the chosen transition and clears the staging.
        $this->jira->shouldReceive('transitionIssue')->once()->with('DEMO-1', '31');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], __('reports.manage.field.status'))
                && str_contains((string) $args[1], 'Closed'));

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertNull($context['pending']);
    }

    public function test_category_action_stages_and_confirms(): void
    {
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturn('customfield_10090');
        $this->jira->shouldReceive('fieldAllowedOptions')->with('DEMO-1', 'customfield_10090')->andReturn([
            ['id' => '101', 'value' => 'Payment'],
        ]);
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issueWithSummary('DEMO-1', ReportType::UserBug->jiraLabel().': Ali'));

        // When action button clicked: shows category options
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $markup = self::replyMarkupWithActions($args[2] ?? [], self::USER_ID);

                return str_contains($markup, CallbackAction::RptSet->value.'category_DEMO-1_101');
            });

        $this->handleCallback(CallbackAction::RptAct->value.EditAction::Category->value.'_DEMO-1');

        // When option chosen: staged
        $this->jira->shouldNotReceive('updateIssueField');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptconfirm_DEMO-1'));

        $this->handleCallback(CallbackAction::RptSet->value.'category_DEMO-1_101');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('DEMO-1', $context['pending_task']);
        $this->assertSame(['value' => '101', 'label' => 'Payment'], $context['pending']['category']);

        // When confirmed: updates Jira field
        $this->jira->shouldReceive('updateIssueField')->once()->with('DEMO-1', 'customfield_10090', ['id' => '101']);
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], __('reports.manage.field.category'))
                && str_contains((string) $args[1], 'Payment'));

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertNull($context['pending']);
    }

    public function test_category_action_is_rejected_for_non_user_bug_tasks(): void
    {
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issueWithSummary('DEMO-1', 'A regular non-bug task'));

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], ReportsMessageResource::fieldUnavailable()));

        $this->handleCallback(CallbackAction::RptAct->value.EditAction::Category->value.'_DEMO-1');
    }

    public function test_change_priority_is_applied_only_on_confirm(): void
    {
        // Priority editing is currently disabled — restore this test together
        // with the commented priority blocks in TaskManagementService.
        $this->markTestSkipped('Priority editing is currently disabled.');

        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();

        $this->jira->shouldNotReceive('updateIssueField');
        $this->messenger->shouldReceive('sendMessage')->twice();

        $this->handleCallback(CallbackAction::RptSet->value.'priority_DEMO-1_High');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame(['value' => 'High', 'label' => 'High'], $context['pending']['priority']);

        $this->jira->shouldReceive('updateIssueField')
            ->once()
            ->with('DEMO-1', 'priority', ['name' => 'High']);

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertEmpty($context['pending']);
    }

    public function test_change_priority_offers_the_task_priority_options(): void
    {
        // Priority editing is currently disabled — restore this test together
        // with the commented priority blocks in TaskManagementService.
        $this->markTestSkipped('Priority editing is currently disabled.');

        $this->jira->shouldNotReceive('customFieldId');

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $data = [];
                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        $data[] = $button['callback_data'];
                    }
                }

                return in_array(CallbackAction::RptSet->value.'priority_DEMO-4_High', $data, true)
                    && in_array(CallbackAction::RptSet->value.'priority_DEMO-4_Lowest', $data, true);
            });

        $this->handleCallback(CallbackAction::RptAct->value.'priority_DEMO-4');
    }

    public function test_change_assignee_uses_the_assignable_users_of_the_issue(): void
    {
        // Group enumeration requires admin privileges on this instance and
        // must NOT be used here.
        $this->jira->shouldNotReceive('getUsers');
        $this->jira->shouldReceive('getAssignableUsers')
            ->once()
            ->with('TS-4')
            ->andReturn([
                ['name' => 'jsmith', 'displayName' => 'John Smith'],
                ['name' => 'alice', 'displayName' => 'Alice'],
            ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);

                // Two users side by side: each row holds two flat button
                // objects — no double-wrapped buttons.
                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        if (! isset($button['text'], $button['callback_data'])) {
                            return false;
                        }
                    }
                }

                return str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptset_assignee_TS-4_jsmith')
                    && str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'John Smith');
            });

        $this->handleCallback(CallbackAction::RptAct->value.'assignee_TS-4');
    }

    public function test_duplicate_callback_deliveries_are_suppressed_by_the_processing_lock(): void
    {
        // First delivery takes the lock and renders the menu; a duplicate
        // arriving while the lock is held must be dropped entirely.
        $this->jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->jira->shouldReceive('customFieldId')->andReturn(null);

        $calls = 0;
        $this->messenger->shouldReceive('sendMessage')
            ->andReturnUsing(function () use (&$calls): object {
                $calls++;

                return new Message(['message_id' => 500 + $calls]);
            });

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        $api->shouldReceive('deleteMessages')->andReturnTrue();
        Telegram::swap($api);

        $service = app(TaskManagementService::class);
        $service->handleCallback($this->callbackQuery(CallbackAction::RptTask->value.'DEMO-1'));

        $this->assertSame(1, $calls, 'Menu rendered exactly once.');
    }

    public function test_confirm_applies_and_removes_the_temporary_buttons(): void
    {
        Cache::put('interactive_msgs_'.self::USER_ID, [501, 502], 30);
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn('customfield_10600');
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        // Staging keeps the temp buttons alive so more edits can be staged.
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => isset($args[2]['reply_markup'])) // staged menu
            ->andReturn(new Message(['message_id' => 601]));

        app(TaskManagementService::class)->handleCallback(
            $this->callbackQuery(CallbackAction::RptSet->value.'impact_DEMO-1_I1')
        );

        $this->assertNotNull(Cache::get('interactive_msgs_'.self::USER_ID));

        $this->jira->shouldReceive('optionIdForCode')
            ->with('DEMO-1', 'customfield_10600', 'I1')
            ->andReturn('10204');
        $this->jira->shouldReceive('updateIssueField')
            ->once()
            ->with('DEMO-1', 'customfield_10600', [['id' => '10204']]);

        $this->messenger->shouldReceive('deleteMessagesQuietly')->once();
        $this->messenger->shouldReceive('sendMessage')->once(); // verification report

        app(TaskManagementService::class)->handleCallback(
            $this->callbackQuery(CallbackAction::RptConfirm->value.'DEMO-1')
        );

        $this->assertNull(Cache::get('interactive_msgs_'.self::USER_ID));
    }

    public function test_discard_clears_staged_edits_without_touching_jira(): void
    {
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldNotReceive('updateIssueField');
        $this->jira->shouldNotReceive('transitionIssue');
        $this->messenger->shouldReceive('sendMessage')->twice();

        $this->handleCallback(CallbackAction::RptSet->value.'impact_DEMO-1_I1');
        $this->handleCallback(CallbackAction::RptDiscard->value.'DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertNull($context['pending']);
    }

    public function test_impact_picker_offers_options_with_a_single_cancel(): void
    {
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn('customfield_10600');

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $data = [];

                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        $data[] = $button['callback_data'] ?? '';
                    }
                }

                $cancels = array_filter($data, fn (string $d): bool => $d === CallbackAction::RptCancel->value);

                return count($cancels) === 1
                    && ! in_array(CallbackAction::FlowCancel->value, $data, true)
                    && ! in_array(CallbackAction::RptSkip->value.'DEMO-1', $data, true);
            });

        $this->handleCallback(CallbackAction::RptAct->value.'impact_DEMO-1');
    }

    public function test_staged_menu_keeps_only_discard_without_a_generic_cancel(): void
    {
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $rows = self::replyRows($args[2] ?? [], self::USER_ID);
                $data = [];

                foreach ($rows as $row) {
                    foreach ($row as $button) {
                        $data[] = $button['callback_data'] ?? '';
                    }
                }

                $rowWith = fn (string $callback): ?array => collect($rows)->first(
                    fn (array $row): bool => collect($row)->contains('callback_data', $callback)
                );
                $confirmRow = $rowWith(CallbackAction::RptConfirm->value.'DEMO-1');
                $discardRow = $rowWith(CallbackAction::RptDiscard->value.'DEMO-1');

                return in_array(CallbackAction::RptConfirm->value.'DEMO-1', $data, true)
                    && in_array(CallbackAction::RptDiscard->value.'DEMO-1', $data, true)
                    && ! in_array(CallbackAction::RptCancel->value, $data, true)
                    && is_array($confirmRow) && count($confirmRow) === 1
                    && is_array($discardRow) && count($discardRow) === 1;
            });

        $this->handleCallback(CallbackAction::RptSet->value.'impact_DEMO-1_I1');
    }

    public function test_confirm_with_nothing_staged_informs_the_admin(): void
    {
        $this->jira->shouldNotReceive('transitionIssue');
        $this->jira->shouldNotReceive('updateIssueField');

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, ReportsMessageResource::nothingStaged(), []);

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');
    }

    public function test_a_failing_field_is_reported_without_blocking_the_others(): void
    {
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('customFieldId')->with('Impact')->andReturn('customfield_10600');
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => isset($args[2]['reply_markup'])); // staged menu

        $this->handleCallback(CallbackAction::RptSet->value.'impact_DEMO-1_I1');

        // Inject a second staged edit whose application will fail.
        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $context['pending']['assignee'] = ['value' => 'ghost', 'label' => 'Ghost'];
        Cache::put('rpt_ctx_'.self::USER_ID, $context, 30);

        $this->jira->shouldReceive('assignIssue')
            ->once()
            ->andThrow(new RuntimeException('Jira API returned HTTP 400'));
        $this->jira->shouldReceive('optionIdForCode')
            ->with('DEMO-1', 'customfield_10600', 'I1')
            ->andReturn('10204');
        $this->jira->shouldReceive('updateIssueField')
            ->once()
            ->with('DEMO-1', 'customfield_10600', [['id' => '10204']]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                $text = (string) $args[1];

                // Per-field verification: one success and one failure line.
                return str_contains($text, 'Extensive') && str_contains($text, 'Ghost')
                    && str_contains($text, '✅') && str_contains($text, '❌');
            });

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertNull($context['pending']);
    }

    public function test_pending_description_replacement_stages_until_confirm(): void
    {
        Cache::put('rpt_desc_'.self::USER_ID, 'DEMO-1', 10);
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->jira->shouldNotReceive('updateDescription');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptconfirm_DEMO-1'));

        $service = app(TaskManagementService::class);

        $this->assertTrue($service->consumePendingText(self::USER_ID, self::CHAT_ID, 'New full description text'));

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('New full description text', $context['pending']['description']['value']);

        // Marker consumed: a second line of text is not swallowed.
        $this->assertFalse($service->consumePendingText(self::USER_ID, self::CHAT_ID, 'more'));
    }

    public function test_too_short_descriptions_are_rejected_without_a_jira_call(): void
    {
        Cache::put('rpt_desc_'.self::USER_ID, 'DEMO-1', 10);

        $this->jira->shouldNotReceive('updateDescription');
        $this->messenger->shouldReceive('sendMessage')->once();

        $this->assertTrue(app(TaskManagementService::class)->consumePendingText(self::USER_ID, self::CHAT_ID, 'hi'));

        // The prompt stays open so the admin can send a longer description.
        $this->assertSame('DEMO-1', Cache::get('rpt_desc_'.self::USER_ID));
    }

    public function test_add_comment_action_prompts_for_text_and_waits_for_it(): void
    {
        $this->jira->shouldNotReceive('addComment');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => $args[1] === ReportsMessageResource::enterComment('DEMO-1')
                && str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), CallbackAction::RptCancel->value));

        $this->handleCallback(CallbackAction::RptAct->value.EditAction::Comment->value.'_DEMO-1');

        $this->assertSame('DEMO-1', Cache::get('rpt_comment_'.self::USER_ID));
    }

    public function test_pending_comment_stages_with_author_footer_until_confirm(): void
    {
        Cache::put('rpt_comment_'.self::USER_ID, 'DEMO-1', 10);
        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->jira->shouldNotReceive('addComment');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptconfirm_DEMO-1'));

        $service = app(TaskManagementService::class);

        $this->assertTrue($service->consumePendingText(self::USER_ID, self::CHAT_ID, '  Checked the logs, retrying tonight.  '));

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $body = $context['pending']['comment']['value'];
        $this->assertStringStartsWith('Checked the logs, retrying tonight.', $body);
        $this->assertStringContainsString(__('reports.jira.comment_footer', ['name' => 'Ali Admin']), $body);

        // Marker consumed: a second line of text is not swallowed.
        $this->assertFalse($service->consumePendingText(self::USER_ID, self::CHAT_ID, 'more'));
    }

    public function test_comment_without_a_linked_display_name_has_no_footer(): void
    {
        $this->mock(AccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isBotAdmin')->andReturnTrue();
            $mock->shouldReceive('isUserLinked')->andReturnTrue();
            $mock->shouldReceive('linkedJiraDisplayName')->andReturnNull();
        });
        Cache::put('rpt_comment_'.self::USER_ID, 'DEMO-1', 10);
        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssue')->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->messenger->shouldReceive('sendMessage')->once();

        app(TaskManagementService::class)->consumePendingText(self::USER_ID, self::CHAT_ID, 'Plain note');

        $this->assertSame('Plain note', Cache::get('rpt_ctx_'.self::USER_ID)['pending']['comment']['value']);
    }

    public function test_too_short_comments_are_rejected_without_staging(): void
    {
        Cache::put('rpt_comment_'.self::USER_ID, 'DEMO-1', 10);

        $this->jira->shouldNotReceive('addComment');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, __('reports.manage.comment_too_short'), []);

        $this->assertTrue(app(TaskManagementService::class)->consumePendingText(self::USER_ID, self::CHAT_ID, 'k'));

        $this->assertSame('DEMO-1', Cache::get('rpt_comment_'.self::USER_ID));
        $this->assertEmpty(Cache::get('rpt_ctx_'.self::USER_ID)['pending'] ?? null);
    }

    public function test_typing_cancel_while_commenting_returns_to_the_task_without_staging(): void
    {
        Cache::put('rpt_comment_'.self::USER_ID, 'DEMO-1', 10);
        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssue')->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->jira->shouldNotReceive('addComment');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptact_comment_DEMO-1'));

        $this->assertTrue(app(TaskManagementService::class)->consumePendingText(self::USER_ID, self::CHAT_ID, '/cancel'));

        $this->assertNull(Cache::get('rpt_comment_'.self::USER_ID));
        $this->assertEmpty(Cache::get('rpt_ctx_'.self::USER_ID)['pending'] ?? null);
    }

    public function test_confirm_posts_the_staged_comment_to_jira(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'pending_task' => 'DEMO-1',
            'pending' => [
                'comment' => ['value' => "Fixed on staging\n\nfooter", 'label' => 'Fixed on staging…'],
            ],
        ], 30);

        $this->jira->shouldReceive('addComment')->once()->with('DEMO-1', "Fixed on staging\n\nfooter")->andReturn(['id' => '10001']);
        $this->jira->shouldNotReceive('updateDescription');
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], '✅')
                && str_contains((string) $args[1], __('reports.manage.field.comment')));

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');

        $this->assertNull(Cache::get('rpt_ctx_'.self::USER_ID)['pending']);
    }

    public function test_failed_comment_post_is_reported_per_field(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'pending_task' => 'DEMO-1',
            'pending' => [
                'comment' => ['value' => 'Note', 'label' => 'Note…'],
            ],
        ], 30);

        $this->jira->shouldReceive('addComment')->once()->andThrow(new RuntimeException('Jira API returned HTTP 403'));
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], '❌'));

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');
    }

    public function test_managetasks_buttons_are_refused_while_a_flow_is_active(): void
    {
        app(ConversationManager::class)->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);

        // The idle guard fires before any Jira interaction.
        $this->jira->shouldNotReceive('getProjectBoard');
        $this->jira->shouldNotReceive('getIssues');
        $this->jira->shouldNotReceive('getIssue');

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::alreadyWaiting(), []);

        $this->handleCallback(CallbackAction::RptProject->value.'DEMO');

        // The running conversation is untouched.
        $this->assertTrue(app(ConversationManager::class)->isActive(self::USER_ID));
    }

    public function test_pending_description_text_yields_to_an_active_flow(): void
    {
        Cache::put('rpt_desc_'.self::USER_ID, 'DEMO-1', 10);
        app(ConversationManager::class)->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);

        $this->messenger->shouldNotReceive('sendMessage');
        $this->jira->shouldNotReceive('updateDescription');

        // Unhandled: the text must reach the active flow instead.
        $this->assertFalse(app(TaskManagementService::class)->consumePendingText(self::USER_ID, self::CHAT_ID, 'New full description text'));

        // The stale marker survives for when the flow ends.
        $this->assertSame('DEMO-1', Cache::get('rpt_desc_'.self::USER_ID));
    }

    public function test_the_entry_picker_is_tracked_as_an_open_session(): void
    {
        $this->jira->shouldReceive('getProjectBoard')
            ->once()
            ->with('TS')
            ->andReturn(null);
        $this->jira->shouldReceive('getProjectStatuses')
            ->once()
            ->with('TS')
            ->andReturn([['id' => '1', 'name' => 'Open']]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), CallbackAction::RptFilter->value))
            ->andReturn(new Message(['message_id' => 701]));

        app(TaskManagementService::class)->openProjectPicker(self::CHAT_ID, self::USER_ID);

        $this->assertSame([701], Cache::get('interactive_msgs_'.self::USER_ID), 'The picker must count as an open session.');
    }

    public function test_entry_click_during_an_opening_in_flight_is_dropped_silently(): void
    {
        // Another delivery of the same click holds the entry mutex (the
        // section is still being opened): this one must be dropped without
        // a Jira call or any message.
        $lock = Cache::lock('rpt_entry_'.self::USER_ID.'_'.sha1('open_picker'), 10);
        $lock->get();

        try {
            $this->jira->shouldNotReceive('getProjects');
            $this->messenger->shouldNotReceive('sendMessage');

            app(TaskManagementService::class)->openProjectPicker(self::CHAT_ID, self::USER_ID);
        } finally {
            $lock->release();
        }
    }

    public function test_reopening_managetasks_while_a_session_is_open_is_refused(): void
    {
        Cache::put('interactive_msgs_'.self::USER_ID, [900], 30);

        // The refusal fires before any Jira interaction and leaves the
        // open session untouched.
        $this->jira->shouldNotReceive('getProjects');

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::alreadyWaiting(), []);

        app(TaskManagementService::class)->openProjectPicker(self::CHAT_ID, self::USER_ID);

        $this->assertTrue(app(InteractiveMessages::class)->hasTracked(self::USER_ID));
    }

    public function test_entry_reopens_after_the_session_is_cleaned_up(): void
    {
        Cache::put('interactive_msgs_'.self::USER_ID, [501], 30);

        // Ending the session (what confirm/discard completion does) clears
        // the tracked menus...
        $this->messenger->shouldReceive('deleteMessagesQuietly')->once();
        app(InteractiveMessages::class)->deleteAll(self::CHAT_ID, self::USER_ID);

        // ...so the section can be entered again.
        $this->jira->shouldReceive('getProjectBoard')
            ->once()
            ->with('TS')
            ->andReturn(null);
        $this->jira->shouldReceive('getProjectStatuses')
            ->once()
            ->with('TS')
            ->andReturn([['id' => '1', 'name' => 'Open']]);
        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Message(['message_id' => 702]));

        app(TaskManagementService::class)->openProjectPicker(self::CHAT_ID, self::USER_ID);

        $this->assertNotNull(Cache::get('interactive_msgs_'.self::USER_ID));
    }

    public function test_userbug_description_edit_shows_user_info_staging(): void
    {
        $summary = ReportType::UserBug->jiraLabel().': test user';
        $issue = $this->issueWithSummary('DEMO-1', $summary);
        $this->jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn($issue);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], 'اطلاعات کاربر'));

        $this->handleCallback(CallbackAction::RptAct->value.'description_DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('DEMO-1', $context['desc_ui_task'] ?? null);
    }

    public function test_non_userbug_description_edit_keeps_original_flow(): void
    {
        $summary = 'Some other task type';
        $issue = $this->issueWithSummary('DEMO-1', $summary);
        $this->jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn($issue);

        $this->messenger->shouldReceive('sendMessage')
            ->once();

        $this->handleCallback(CallbackAction::RptAct->value.'description_DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID) ?? [];
        $this->assertArrayNotHasKey('desc_ui_task', $context);
    }

    public function test_userbug_user_info_field_saves_value_and_re_renders(): void
    {
        $this->setupUserBugContext();
        Cache::put('rpt_ctx_'.self::USER_ID, array_merge(
            Cache::get('rpt_ctx_'.self::USER_ID) ?: [],
            ['desc_ui_staging_field' => 'user_name'],
        ), 30);

        $this->messenger->shouldReceive('sendMessage');

        $service = app(TaskManagementService::class);
        $result = $service->consumePendingText(self::USER_ID, self::CHAT_ID, 'This is the user full name');

        $this->assertTrue($result);

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('This is the user full name', $context['desc_ui_data']['user_name'] ?? null);
        $this->assertSame('', $context['desc_ui_staging_field'] ?? null);
    }

    public function test_userbug_user_info_text_input_saves_and_re_renders(): void
    {
        $this->setupUserBugContext();
        Cache::put('rpt_ctx_'.self::USER_ID, array_merge(
            Cache::get('rpt_ctx_'.self::USER_ID) ?: [],
            ['desc_ui_staging_field' => 'user_name'],
        ), 30);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains((string) $args[1], 'user_name') || str_contains((string) $args[1], 'نام کاربری'));

        $service = app(TaskManagementService::class);
        $result = $service->consumePendingText(self::USER_ID, self::CHAT_ID, 'test_user');

        $this->assertTrue($result);

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('test_user', $context['desc_ui_data']['user_name'] ?? null);
        $this->assertSame('', $context['desc_ui_staging_field'] ?? null);
    }

    public function test_userbug_confirm_without_any_field_shows_error(): void
    {
        $this->setupUserBugContext();

        $this->messenger->shouldReceive('sendMessage');

        $service = app(TaskManagementService::class);
        $method = new \ReflectionMethod($service, 'handleDescUserInfoCallback');
        $method->invoke($service, 'confirm_DEMO-1', self::CHAT_ID, self::USER_ID);
    }

    public function test_userbug_confirm_with_fields_prompts_for_description(): void
    {
        $this->setupUserBugContext(['user_name' => 'ali']);

        $this->messenger->shouldReceive('sendMessage');

        $service = app(TaskManagementService::class);
        $method = new \ReflectionMethod($service, 'handleDescUserInfoCallback');
        $method->invoke($service, 'confirm_DEMO-1', self::CHAT_ID, self::USER_ID);

        $context = Cache::get('rpt_desc_ui_'.self::USER_ID);
        $this->assertSame('DEMO-1', $context);
    }

    public function test_userbug_description_text_stages_with_wiki_markup(): void
    {
        $this->setupUserBugContext(['user_name' => 'ali', 'user_identifier' => 'ID-1']);
        Cache::put('rpt_desc_ui_'.self::USER_ID, 'DEMO-1', 10);

        $this->jira->shouldReceive('customFieldId')->andReturn(null)->byDefault();
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptconfirm_DEMO-1'));

        $service = app(TaskManagementService::class);
        $result = $service->consumePendingText(self::USER_ID, self::CHAT_ID, 'This is a test description for the bug report');

        $this->assertTrue($result);

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $description = $context['pending']['description']['value'] ?? '';
        $this->assertStringContainsString('h3. توضیحات', $description);
        $this->assertStringContainsString('This is a test description for the bug report', $description);
        $this->assertStringContainsString('h3. اطلاعات کاربر', $description);
        $this->assertStringContainsString('نام کاربری:', $description);
        $this->assertStringContainsString('شناسه کاربر:', $description);
    }

    public function test_userbug_description_text_too_short_is_rejected(): void
    {
        $this->setupUserBugContext(['user_name' => 'ali']);
        Cache::put('rpt_desc_ui_'.self::USER_ID, 'DEMO-1', 10);

        $this->messenger->shouldReceive('sendMessage')->once();

        $service = app(TaskManagementService::class);
        $result = $service->consumePendingText(self::USER_ID, self::CHAT_ID, 'hi');

        $this->assertTrue($result);
        $this->assertSame('DEMO-1', Cache::get('rpt_desc_ui_'.self::USER_ID));
    }

    public function test_userbug_confirm_updates_summary_with_user_info(): void
    {
        $summary = ReportType::UserBug->jiraLabel().': old user, OLD-1';
        $issue = $this->issueWithSummary('DEMO-1', $summary);

        Cache::put('rpt_ctx_'.self::USER_ID, [
            'pending_task' => 'DEMO-1',
            'pending' => [
                'description' => ['value' => 'h3. توضیحات'."\n".'New description text', 'label' => 'New description…'],
            ],
            'desc_ui_user_info' => ['user_name' => 'ali', 'user_identifier' => 'ID-1', 'user_mobile' => '0912'],
        ], 30);

        $this->jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn($issue);
        $this->jira->shouldReceive('updateDescription')->with('DEMO-1', 'h3. توضیحات'."\n".'New description text');
        $this->jira->shouldReceive('updateIssueField')->with('DEMO-1', 'summary', ReportType::UserBug->jiraLabel().': ali, ID-1');
        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->messenger->shouldReceive('sendMessage')->once();

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');
    }

    public function test_userbug_confirm_without_user_info_does_not_update_summary(): void
    {
        $summary = ReportType::UserBug->jiraLabel().': existing user';
        $issue = $this->issueWithSummary('DEMO-1', $summary);

        Cache::put('rpt_ctx_'.self::USER_ID, [
            'pending_task' => 'DEMO-1',
            'pending' => [
                'description' => ['value' => 'h3. توضیحات'."\n".'New description', 'label' => 'New desc…'],
            ],
        ], 30);

        $this->jira->shouldReceive('getIssue')->with('DEMO-1')->andReturn($issue);
        $this->jira->shouldReceive('updateDescription')->with('DEMO-1', 'h3. توضیحات'."\n".'New description');
        $this->jira->shouldNotReceive('updateIssueField');
        $this->jira->shouldReceive('customFieldId')->andReturn(null);
        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));

        $this->messenger->shouldReceive('sendMessage')->once();

        $this->handleCallback(CallbackAction::RptConfirm->value.'DEMO-1');
    }

    public function test_cancel_task_view_returns_to_open_task_list(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'project' => 'DEMO',
            'status_id' => '10306',
            'status_name' => 'Raised',
            'page' => 1,
            'current_task' => 'DEMO-1',
        ], 30);
        Cache::put('rpt_statuses_'.self::USER_ID, ['10306' => 'Raised'], 30);

        $this->jira->shouldReceive('getIssues')
            ->once()
            ->andReturn([
                'issues' => [$this->issue('DEMO-1', '10306', 'Raised')],
                'hasMore' => false,
            ]);
        $this->jira->shouldReceive('customFieldId')->andReturn(null);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), CallbackAction::RptTask->value.'DEMO-1'));

        $this->handleCallback(CallbackAction::RptCancelTask->value.'DEMO-1');

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertNull($context['current_task']);
        $this->assertSame('Raised', $context['status_name']);
    }

    public function test_cancel_edit_section_returns_to_task_detail(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'project' => 'DEMO',
            'status_id' => '10306',
            'status_name' => 'Raised',
            'page' => 1,
            'current_task' => 'DEMO-1',
            'editing_task' => 'DEMO-1',
            'editing_field' => 'owner',
        ], 30);

        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->jira->shouldReceive('customFieldId')->andReturn(null);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptact_status_DEMO-1'));

        $this->handleCallback(CallbackAction::RptCancel->value);

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('DEMO-1', $context['current_task']);
        $this->assertNull($context['editing_task']);
        $this->assertNull($context['editing_field']);
    }

    public function test_cancel_edit_section_with_staged_edits_returns_to_staged_menu(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'project' => 'DEMO',
            'status_id' => '10306',
            'status_name' => 'Raised',
            'page' => 1,
            'current_task' => 'DEMO-1',
            'editing_task' => 'DEMO-1',
            'editing_field' => 'owner',
            'pending_task' => 'DEMO-1',
            'pending' => [
                'status' => ['value' => '31', 'label' => 'Verified'],
            ],
        ], 30);

        $this->jira->shouldReceive('getIssue')
            ->with('DEMO-1')
            ->andReturn($this->issue('DEMO-1', '10306', 'Raised'));
        $this->jira->shouldReceive('customFieldId')->andReturn(null);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), 'rptconfirm_DEMO-1'));

        $this->handleCallback(CallbackAction::RptCancel->value);

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertSame('DEMO-1', $context['current_task']);
        $this->assertNull($context['editing_task']);
        $this->assertNotNull($context['pending']);
    }

    public function test_cancel_task_list_returns_to_status_menu(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, [
            'project' => 'DEMO',
            'status_id' => '10306',
            'status_name' => 'Raised',
            'page' => 1,
            'current_task' => null,
            'editing_task' => null,
        ], 30);

        $this->jira->shouldReceive('getProjectBoard')->with('DEMO')->andReturn(7);
        $this->jira->shouldReceive('getBoardConfiguration')->with(7)->andReturn([
            'statuses' => ['Raised'],
            'status_rows' => [
                ['id' => '10306', 'name' => 'Raised', 'category' => 'To Do'],
            ],
            'project_key' => 'DEMO',
        ]);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (...$args): bool => str_contains(self::replyMarkupWithActions($args[2] ?? [], self::USER_ID), CallbackAction::RptFilter->value.'10306'));

        $this->handleCallback(CallbackAction::RptCancel->value);

        $context = Cache::get('rpt_ctx_'.self::USER_ID);
        $this->assertNull($context['status_id']);
        $this->assertNull($context['status_name']);
    }

    public function test_exit_leaves_the_session_from_any_screen_and_restores_the_main_menu(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['mode' => 'manage', 'project' => 'DEMO', 'current_task' => 'DEMO-1', 'editing_task' => 'DEMO-1'], 30);
        Cache::put('rpt_comment_'.self::USER_ID, 'DEMO-1', 10);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text, array $extra): bool => $text === FlowMessageResource::flowCancelled()
                && in_array(__('keyboard.button.manage_tasks'), array_column(array_merge(...self::replyRows($extra, self::USER_ID)), 'text'), true));

        $this->handleCallback(CallbackAction::RptExit->value);

        $this->assertNull(Cache::get('rpt_comment_'.self::USER_ID));
        $this->assertNull(Cache::get('rpt_ctx_'.self::USER_ID)['current_task']);
        $this->assertNull(app(ReplyKeyboardService::class)->resolve(self::USER_ID, __('keyboard.button.back')));
    }

    public function test_back_from_the_timeline_status_menu_returns_to_the_timeline_menu(): void
    {
        Cache::put('rpt_ctx_'.self::USER_ID, ['mode' => 'timeline', 'project' => 'TS'], 30);

        $this->messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (int $chatId, string $text): bool => $text === __('timeline.menu.title'));

        $this->handleCallback(CallbackAction::RptCancel->value);
    }

    private function setupUserBugContext(array $data = []): void
    {
        $context = array_merge([
            'desc_ui_task' => 'DEMO-1',
            'desc_ui_data' => $data,
            'desc_ui_staging_field' => '',
        ], $data !== [] ? [] : []);

        Cache::put('rpt_ctx_'.self::USER_ID, $context, 30);
    }

    private function issueWithSummary(string $key, string $summary): array
    {
        return [
            'key' => $key,
            'fields' => [
                'summary' => $summary,
                'status' => ['id' => '10306', 'name' => 'Raised'],
                'updated' => '2025-01-01T00:00:00.000+0000',
            ],
        ];
    }

    private function issue(string $key, string $statusId, string $statusName, ?string $severity = null, ?string $impact = null, ?string $updated = null): array
    {
        return [
            'key' => $key,
            'fields' => array_filter([
                'summary' => "Summary of {$key}",
                'status' => ['id' => $statusId, 'name' => $statusName],
                'customfield_1' => $severity === null ? null : ['value' => $severity],
                'customfield_2' => $impact === null ? null : (is_numeric($impact) ? (int) $impact : ['value' => $impact]),
                'updated' => $updated,
            ], fn ($v): bool => $v !== null),
        ];
    }

    private function handleCallback(string $data): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        Telegram::swap($api);

        app(TaskManagementService::class)->handleCallback($this->callbackQuery($data));
    }

    private function callbackQuery(string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id' => 'cbq-1',
            'from' => ['id' => self::USER_ID],
            'message' => ['message_id' => 10, 'chat' => ['id' => self::CHAT_ID, 'type' => 'private']],
            'data' => $data,
        ]);
    }
}
