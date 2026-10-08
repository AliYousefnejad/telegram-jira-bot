<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Resources\KeyboardResource;
use App\Resources\TaskKeyboardResource;
use App\Resources\TimelineResource;
use App\Services\AccountLinkService;
use App\Services\Jira\JiraService;
use App\Services\Jira\TaskService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\CallbackService;
use App\Services\Telegram\Reports\TaskManagementService;
use App\Services\Telegram\UpdateProcessor;
use App\Support\CallbackData;
use App\Support\CommentFooter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class TimelineServiceTest extends TestCase
{
    public function test_jira_service_get_issue_changelog_filters_status_and_assignee_events(): void
    {
        config([
            'services.jira.base_url' => 'https://jira.example.com',
            'services.jira.pat' => 'test-token',
        ]);

        Http::fake([
            'https://jira.example.com/rest/api/2/issue/DEMO-1*' => Http::response([
                'id' => '10001',
                'key' => 'DEMO-1',
                'fields' => [
                    'created' => '2026-03-01T08:00:00.000+0330',
                    'creator' => ['displayName' => 'John Doe', 'name' => 'john.doe'],
                    'status' => ['name' => 'Closed'],
                    'assignee' => ['displayName' => 'Alice Tech'],
                ],
                'changelog' => [
                    'total' => 3,
                    'histories' => [
                        [
                            'id' => '101',
                            'created' => '2026-03-01T09:00:00.000+0330',
                            'author' => ['displayName' => 'Manager'],
                            'items' => [
                                [
                                    'field' => 'status',
                                    'fromString' => 'Raised',
                                    'toString' => 'In Progress',
                                ],
                                [
                                    'field' => 'description',
                                    'fromString' => 'old desc',
                                    'toString' => 'new desc',
                                ],
                            ],
                        ],
                        [
                            'id' => '102',
                            'created' => '2026-03-01T09:30:00.000+0330',
                            'author' => ['displayName' => 'Manager'],
                            'items' => [
                                [
                                    'field' => 'assignee',
                                    'fromString' => null,
                                    'toString' => 'Alice Tech',
                                ],
                            ],
                        ],
                        [
                            'id' => '103',
                            'created' => '2026-03-01T11:00:00.000+0330',
                            'author' => ['displayName' => 'Alice Tech'],
                            'items' => [
                                [
                                    'field' => 'status',
                                    'fromString' => 'In Progress',
                                    'toString' => 'Closed',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $jira = app(JiraService::class);
        $result = $jira->getIssueChangelog('DEMO-1');

        $this->assertSame('2026-03-01T08:00:00.000+0330', $result['createdAt']);
        $this->assertSame('John Doe', $result['createdBy']);
        $this->assertSame('Closed', $result['currentStatus']);
        $this->assertSame('Alice Tech', $result['currentAssignee']);
        $this->assertCount(3, $result['events']);

        $this->assertSame('status', $result['events'][0]['type']);
        $this->assertSame('Raised', $result['events'][0]['from']);
        $this->assertSame('In Progress', $result['events'][0]['to']);
        $this->assertSame('Manager', $result['events'][0]['author']);

        $this->assertSame('assignee', $result['events'][1]['type']);
        $this->assertNull($result['events'][1]['from']);
        $this->assertSame('Alice Tech', $result['events'][1]['to']);

        $this->assertSame('status', $result['events'][2]['type']);
        $this->assertSame('In Progress', $result['events'][2]['from']);
        $this->assertSame('Closed', $result['events'][2]['to']);
    }

    public function test_jira_service_get_issue_changelog_prefers_reporter_over_creator(): void
    {
        config([
            'services.jira.base_url' => 'https://jira.example.com',
            'services.jira.pat' => 'test-token',
        ]);

        Http::fake([
            'https://jira.example.com/rest/api/2/issue/DEMO-2*' => Http::response([
                'id' => '10002',
                'key' => 'DEMO-2',
                'fields' => [
                    'created' => '2026-03-01T08:00:00.000+0330',
                    'creator' => ['displayName' => 'Bot PAT User', 'name' => 'bot.pat'],
                    'reporter' => ['displayName' => 'Real Reporter', 'name' => 'real.reporter'],
                    'status' => ['name' => 'Open'],
                    'assignee' => null,
                ],
                'changelog' => [
                    'total' => 0,
                    'histories' => [],
                ],
            ], 200),
        ]);

        $jira = app(JiraService::class);
        $result = $jira->getIssueChangelog('DEMO-2');

        $this->assertSame('Real Reporter', $result['createdBy']);
    }

    public function test_jira_service_changelog_includes_owner_changes_and_paged_comments(): void
    {
        config([
            'services.jira.base_url' => 'https://jira.example.com',
            'services.jira.pat' => 'test-token',
            'services.jira.custom_fields.owner' => 'Owner',
        ]);
        Cache::flush();

        Http::fake([
            'https://jira.example.com/rest/api/2/field' => Http::response([
                ['id' => 'customfield_10500', 'name' => 'Owner', 'custom' => true],
            ]),
            'https://jira.example.com/rest/api/2/issue/DEMO-7/comment*' => Http::response([
                'comments' => [
                    ['created' => '2026-03-01T12:00:00.000+0330', 'author' => ['displayName' => 'Sara'], 'body' => 'second page'],
                ],
                'total' => 2,
            ]),
            'https://jira.example.com/rest/api/2/issue/DEMO-7*' => Http::response([
                'key' => 'DEMO-7',
                'fields' => [
                    'created' => '2026-03-01T08:00:00.000+0330',
                    'status' => ['name' => 'Raised'],
                    'comment' => [
                        'comments' => [
                            ['created' => '2026-03-01T09:00:00.000+0330', 'author' => ['displayName' => 'Reza'], 'body' => 'first page'],
                        ],
                        'total' => 2,
                        'maxResults' => 1,
                    ],
                ],
                'changelog' => [
                    'total' => 1,
                    'histories' => [[
                        'created' => '2026-03-01T10:00:00.000+0330',
                        'author' => ['displayName' => 'Jira Bot'],
                        'items' => [
                            // Matched by field name.
                            ['field' => 'Owner', 'fieldtype' => 'custom', 'fromString' => null, 'toString' => 'L1'],
                            // Renamed field, matched only by its id.
                            ['field' => 'Ticket Owner', 'fieldtype' => 'custom', 'fieldId' => 'customfield_10500', 'fromString' => 'L1', 'toString' => 'L2'],
                            // Other custom fields stay out of the timeline.
                            ['field' => 'Severity', 'fieldtype' => 'custom', 'fieldId' => 'customfield_10600', 'fromString' => 'S3', 'toString' => 'S1'],
                        ],
                    ]],
                ],
            ]),
        ]);

        $result = app(JiraService::class)->getIssueChangelog('DEMO-7');

        $this->assertSame(['comment', 'owner', 'owner', 'comment'], array_column($result['events'], 'type'));
        $this->assertSame('first page', $result['events'][0]['body']);
        $this->assertSame('Reza', $result['events'][0]['author']);
        $this->assertSame(['L1', 'L2'], [$result['events'][1]['to'], $result['events'][2]['to']]);
        $this->assertSame('second page', $result['events'][3]['body']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/issue/DEMO-7?')
            && str_contains(urldecode($request->url()), 'comment'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/issue/DEMO-7/comment')
            && (int) $request['startAt'] === 1);
    }

    public function test_task_keyboard_includes_timeline_button(): void
    {
        $keyboard = TaskKeyboardResource::taskDetail('DEMO-1');
        $rows = $keyboard['inline_keyboard'];

        $found = false;
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? '') === CallbackData::timeline('DEMO-1')) {
                    $found = true;
                    $this->assertSame(__('keyboard.button.timeline'), $button['text']);
                }
            }
        }

        $this->assertTrue($found, 'Timeline button was not found in taskDetail keyboard');
    }

    public function test_task_keyboard_manage_mode_includes_timeline_button(): void
    {
        $keyboard = TaskKeyboardResource::taskDetail('DEMO-1', manage: true);
        $rows = $keyboard['inline_keyboard'];

        $found = false;
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? '') === CallbackData::timeline('DEMO-1')) {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'Timeline button was not found in manage taskDetail keyboard');
    }

    public function test_timeline_resource_renders_an_english_status_journey(): void
    {
        $changelog = [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => 'Ali',
            'currentStatus' => 'Closed',
            'currentAssignee' => 'Reza',
            'events' => [
                [
                    'type' => 'status',
                    'timestamp' => '2026-03-01T09:30:00.000+0330',
                    'author' => 'Admin',
                    'from' => 'Raised',
                    'to' => 'Under Triage',
                ],
                [
                    'type' => 'assignee',
                    'timestamp' => '2026-03-01T09:35:00.000+0330',
                    'author' => 'Admin',
                    'from' => null,
                    'to' => 'Reza',
                ],
                [
                    'type' => 'status',
                    'timestamp' => '2026-03-01T12:00:00.000+0330',
                    'author' => 'Reza',
                    'from' => 'Under Triage',
                    'to' => 'Closed',
                ],
            ],
        ];

        $output = TimelineResource::format('DEMO-42', $changelog, Date::parse('2026-03-01T14:15:00.000+0330'));

        $this->assertStringStartsWith('📊 DEMO-42 · Timeline', $output);
        $this->assertStringContainsString('📍 Now: Closed · ⏳ 2h 15m', $output);
        $this->assertStringContainsString('👤 Assignee: Reza', $output);
        $this->assertStringContainsString('🗓️ Created: 1404/12/10 08:00 by Ali (6h 15m ago)', $output);
        $this->assertStringContainsString('🔢 Changes: 🔄 2 status · 👥 1 assignee', $output);

        // One block per status, each lasting until the next one was entered.
        $this->assertStringContainsString("✅ 1. Raised · ⏱️ 1h 30m\n   🆕 1404/12/10 08:00 · created by Ali", $output);
        $this->assertStringContainsString("✅ 2. Under Triage · ⏱️ 2h 30m\n   🔄 1404/12/10 09:30 · moved by Admin", $output);
        $this->assertStringContainsString("🟢 3. Closed · ⏱️ 2h 15m (current)\n   🔄 1404/12/10 12:00 · moved by Reza", $output);

        // The assignee change sits inside the status it happened in.
        $this->assertStringContainsString(
            "moved by Admin\n   👤 Unassigned ➜ Reza\n      🕒 1404/12/10 09:35 · by Admin\n\n🟢 3. Closed",
            $output,
        );
        $this->assertStringNotContainsString(__('timeline.report.no_changes', [], 'en'), $output);
    }

    public function test_timeline_is_english_and_ltr_even_under_the_persian_locale(): void
    {
        app()->setLocale('fa');

        $output = TimelineResource::format('TS-32', [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => 'Ali',
            'currentStatus' => 'Raised',
            'currentAssignee' => null,
            'events' => [],
        ], Date::parse('2026-03-01T14:15:00.000+0330'));

        $this->assertStringNotContainsString("\xE2\x80\x8F", $output);
        $this->assertStringNotContainsString("\xE2\x81\xA6", $output);
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $output);
        $this->assertStringContainsString('👤 Assignee: Unassigned', $output);
        $this->assertStringContainsString('🟢 1. Raised · ⏱️ 6h 15m (current)', $output);
        $this->assertStringContainsString('ℹ️ No status, assignee, owner or comment activity yet.', $output);
    }

    public function test_assignee_changes_before_the_first_status_change_belong_to_the_initial_status(): void
    {
        $output = TimelineResource::format('TS-1', [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => null,
            'currentStatus' => 'Triage',
            'currentAssignee' => 'Sara',
            'events' => [
                // Out of order on purpose: the journey is built chronologically.
                ['type' => 'status', 'timestamp' => '2026-03-01T10:00:00.000+0330', 'author' => null, 'from' => 'Raised', 'to' => 'Triage'],
                ['type' => 'assignee', 'timestamp' => '2026-03-01T09:00:00.000+0330', 'author' => 'Lead', 'from' => '', 'to' => 'Sara'],
            ],
        ], Date::parse('2026-03-01T11:00:00.000+0330'));

        $this->assertStringContainsString(
            "✅ 1. Raised · ⏱️ 2h\n   🆕 1404/12/10 08:00 · created by unknown\n   👤 Unassigned ➜ Sara",
            $output,
        );
        $this->assertStringContainsString("🟢 2. Triage · ⏱️ 1h (current)\n   🔄 1404/12/10 10:00 · moved by unknown", $output);
    }

    public function test_timeline_times_use_the_24_hour_clock_in_tehran_time(): void
    {
        $output = TimelineResource::format('TS-7', [
            // 11:27 UTC is 14:57 in Tehran — must never render as 2:57.
            'createdAt' => '2025-09-21T11:27:00.000+0000',
            'createdBy' => 'Ali',
            'currentStatus' => 'Raised',
            'currentAssignee' => null,
            'events' => [
                ['type' => 'assignee', 'timestamp' => '2025-09-21T20:10:00.000+0330', 'author' => 'Lead', 'from' => null, 'to' => 'Sara'],
            ],
        ], Date::parse('2025-09-21T21:00:00.000+0330'));

        $this->assertStringContainsString('🗓️ Created: 1404/06/30 14:57 by Ali', $output);
        $this->assertStringContainsString('🆕 1404/06/30 14:57 · created by Ali', $output);
        $this->assertStringContainsString('🕒 1404/06/30 20:10 · by Lead', $output);
        $this->assertStringNotContainsString(' 2:57', $output);
    }

    public function test_timeline_shows_owner_changes_comments_and_last_change(): void
    {
        app()->setLocale('fa');

        $botComment = CommentFooter::append('Gateway timeout on the *provider* side, escalating to [~devops].', 'Reza Ahmadi');

        $output = TimelineResource::format('TS-42', [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => 'Ali',
            'currentStatus' => 'Triage',
            'currentAssignee' => 'Reza Ahmadi',
            'events' => [
                ['type' => 'owner', 'timestamp' => '2026-03-01T08:00:00.000+0330', 'author' => 'Jira Bot', 'from' => null, 'to' => 'L1'],
                ['type' => 'comment', 'timestamp' => '2026-03-01T08:45:00.000+0330', 'author' => 'Support 1', 'from' => null, 'to' => null, 'body' => 'Can you send a screenshot?'],
                ['type' => 'status', 'timestamp' => '2026-03-01T10:00:00.000+0330', 'author' => 'Support 1', 'from' => 'Raised', 'to' => 'Triage'],
                ['type' => 'owner', 'timestamp' => '2026-03-01T10:00:00.000+0330', 'author' => 'Jira Bot', 'from' => 'L1', 'to' => '🧑‍💻 L2 - Developers'],
                ['type' => 'comment', 'timestamp' => '2026-03-01T12:10:00.000+0330', 'author' => 'Jira Bot', 'from' => null, 'to' => null, 'body' => $botComment],
            ],
        ], Date::parse('2026-03-01T14:10:00.000+0330'));

        // Summary: current owner, last change, counts and first response time.
        $this->assertStringContainsString('👑 Owner: L2', $output);
        $this->assertStringContainsString('🕒 Last change: 💬 comment by Reza Ahmadi · 2h ago', $output);
        $this->assertStringContainsString('🔢 Changes: 🔄 1 status · 👥 0 assignee · 👑 2 owner · 💬 2 comments', $output);
        $this->assertStringContainsString('⏱️ First response: 45m after creation', $output);

        // Owner and comment activity sits inside the status it happened in, with the wait since the previous activity.
        $this->assertStringContainsString(
            "   👑 Owner: — ➜ L1\n      🕒 1404/12/10 08:00 · by Jira Bot\n   💬 1404/12/10 08:45 · Support 1 (+45m)\n      “Can you send a screenshot?”\n\n🟢 2. Triage",
            $output,
        );
        $this->assertStringContainsString("   👑 Owner: L1 ➜ L2\n      🕒 1404/12/10 10:00 · by Jira Bot", $output);

        // Bot-posted comments credit the admin from the footer, which is stripped with the wiki markup.
        $this->assertStringContainsString('💬 1404/12/10 12:10 · Reza Ahmadi (+2h 10m)', $output);
        $this->assertStringContainsString('“Gateway timeout on the provider side, escalating to @devops.”', $output);
        $this->assertStringNotContainsString('Jira Bot (+', $output);
        $this->assertStringNotContainsString('ربات تلگرام', $output);
    }

    public function test_long_multiline_comments_are_previewed_on_one_line(): void
    {
        $output = TimelineResource::format('TS-1', [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => 'Ali',
            'currentStatus' => 'Raised',
            'currentAssignee' => null,
            'events' => [
                ['type' => 'comment', 'timestamp' => '2026-03-01T09:00:00.000+0330', 'author' => 'Sara', 'from' => null, 'to' => null, 'body' => "h3. Findings\nline one\n\n".str_repeat('word ', 60)],
            ],
        ], Date::parse('2026-03-01T10:00:00.000+0330'));

        preg_match('/“(.*)”/u', $output, $match);
        $this->assertNotEmpty($match);
        $this->assertStringStartsWith('Findings line one word', $match[1]);
        $this->assertStringEndsWith('…', $match[1]);
        $this->assertLessThanOrEqual(121, mb_strlen($match[1]));
        $this->assertStringNotContainsString('👑 Owner:', $output);
    }

    public function test_summary_omits_owner_last_change_and_first_response_without_activity(): void
    {
        $output = TimelineResource::format('TS-2', [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => 'Ali',
            'currentStatus' => 'Raised',
            'currentAssignee' => null,
            'events' => [],
        ], Date::parse('2026-03-01T10:00:00.000+0330'));

        $this->assertStringNotContainsString('👑 Owner:', $output);
        $this->assertStringNotContainsString('Last change', $output);
        $this->assertStringNotContainsString('First response', $output);
        $this->assertStringContainsString('ℹ️ No status, assignee, owner or comment activity yet.', $output);
    }

    public function test_last_change_can_be_an_owner_change(): void
    {
        $output = TimelineResource::format('TS-3', [
            'createdAt' => '2026-03-01T08:00:00.000+0330',
            'createdBy' => 'Ali',
            'currentStatus' => 'Raised',
            'currentAssignee' => null,
            'events' => [
                ['type' => 'owner', 'timestamp' => '2026-03-01T09:00:00.000+0330', 'author' => 'CTO', 'from' => 'L1', 'to' => 'CTO'],
            ],
        ], Date::parse('2026-03-01T10:00:00.000+0330'));

        $this->assertStringContainsString('🕒 Last change: 👑 owner set to CTO by CTO · 1h ago', $output);
    }

    public function test_timeline_resource_time_ago(): void
    {
        $now = Date::parse('2026-03-01T10:00:00Z');

        $this->assertSame('just now', TimelineResource::timeAgo('2026-03-01T09:59:45Z', $now));
        $this->assertSame('1m ago', TimelineResource::timeAgo('2026-03-01T09:59:00Z', $now));
        $this->assertSame('2h ago', TimelineResource::timeAgo('2026-03-01T08:00:00Z', $now));
    }

    public function test_timeline_resource_human_duration(): void
    {
        app()->setLocale('fa');

        $this->assertSame('just now', TimelineResource::humanDuration('2026-03-01T10:00:00Z', '2026-03-01T10:00:20Z'));
        $this->assertSame('15m', TimelineResource::humanDuration('2026-03-01T10:00:00Z', '2026-03-01T10:15:00Z'));
        $this->assertSame('2h', TimelineResource::humanDuration('2026-03-01T10:00:00Z', '2026-03-01T12:00:00Z'));
        $this->assertSame('2h 30m', TimelineResource::humanDuration('2026-03-01T10:00:00Z', '2026-03-01T12:30:00Z'));
        $this->assertSame('1d 5h', TimelineResource::humanDuration('2026-03-01T10:00:00Z', '2026-03-02T15:00:00Z'));
        $this->assertSame('3d', TimelineResource::humanDuration('2026-03-01T10:00:00Z', '2026-03-04T10:00:00Z'));
    }

    public function test_callback_service_dispatches_timeline_action(): void
    {
        $taskService = $this->mock(TaskService::class);
        $taskService->shouldReceive('sendTimeline')
            ->once()
            ->with(12345, 'DEMO-1', null);

        $this->mock(AccountLinkService::class);
        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('answerCallback')->once();

        $callbackQuery = new CallbackQuery([
            'id' => 'cb_123',
            'data' => CallbackAction::Timeline->value.'DEMO-1',
            'from' => ['id' => 999],
            'message' => [
                'message_id' => 456,
                'chat' => ['id' => 12345, 'type' => 'private'],
            ],
        ]);

        app(CallbackService::class)->handleCallbackQuery($callbackQuery);
    }

    public function test_task_service_send_timeline_fetches_changelog_and_sends_message(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getIssueChangelog')
            ->once()
            ->with('DEMO-10')
            ->andReturn([
                'createdAt' => '2026-03-01T08:00:00.000+0330',
                'createdBy' => 'Reporter',
                'currentStatus' => 'Open',
                'currentAssignee' => null,
                'events' => [],
            ]);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                return $chatId === 555 && str_contains($text, 'DEMO-10');
            });

        app(TaskService::class)->sendTimeline(555, 'DEMO-10');
    }

    public function test_update_processor_handles_timeline_command_with_key(): void
    {
        $taskService = $this->mock(TaskService::class);
        $taskService->shouldReceive('sendTimelineForCommand')
            ->once()
            ->with(555, 'DEMO-10', null);

        $this->mock(TelegramMessengerInterface::class);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturn(true);
        $access->shouldReceive('isBotAdmin')->andReturn(true);

        $update = new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'text' => '/timeline DEMO-10',
                'from' => ['id' => 1234, 'first_name' => 'Test'],
                'chat' => ['id' => 555, 'type' => 'private'],
            ],
        ]);

        app(UpdateProcessor::class)->process($update);
    }

    public function test_main_menu_includes_timeline_button(): void
    {
        $keyboard = KeyboardResource::mainMenu();
        $rows = $keyboard['keyboard'] ?? $keyboard['inline_keyboard'];

        $found = false;
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['text'] ?? '') === (string) __('keyboard.button.timeline')) {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'Timeline button was not found in main menu keyboard');
    }

    public function test_update_processor_timeline_command_without_args_opens_timeline_menu(): void
    {
        $reportsTasks = $this->mock(TaskManagementService::class);
        $reportsTasks->shouldReceive('consumePendingText')->andReturn(false)->byDefault();
        $reportsTasks->shouldReceive('openTimelineMenu')
            ->once()
            ->with(555, 1234, 10, false, null);

        $this->mock(TelegramMessengerInterface::class);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturn(true);
        $access->shouldReceive('isBotAdmin')->andReturn(true);

        $update = new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'text' => '/timeline',
                'from' => ['id' => 1234, 'first_name' => 'Test'],
                'chat' => ['id' => 555, 'type' => 'private'],
            ],
        ]);

        app(UpdateProcessor::class)->process($update);
    }

    public function test_update_processor_reports_menu_timeline_opens_timeline_menu(): void
    {
        $reportsTasks = $this->mock(TaskManagementService::class);
        $reportsTasks->shouldReceive('openTimelineMenu')
            ->once()
            ->with(555, 1234, 10, false, null);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('answerCallback')->once();

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturn(true);
        $access->shouldReceive('isBotAdmin')->andReturn(true);

        $update = new Update([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb_123',
                'data' => CallbackAction::RptMenu->value.'timeline',
                'from' => ['id' => 1234, 'first_name' => 'Test'],
                'message' => [
                    'message_id' => 10,
                    'chat' => ['id' => 555, 'type' => 'private'],
                ],
            ],
        ]);

        app(UpdateProcessor::class)->process($update);
    }

    public function test_task_management_service_timeline_mode_calls_send_timeline(): void
    {
        $taskService = $this->mock(TaskService::class);
        // The timeline ends the session, so it brings back the main menu.
        $taskService->shouldReceive('sendTimeline')
            ->once()
            ->with(555, 'DEMO-1', null, \Mockery::on(fn (array $params): bool => str_contains($params['reply_markup'] ?? '', 'keyboard')));

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('answerCallback')->once();

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturn(true);
        $access->shouldReceive('isBotAdmin')->andReturn(true);

        Cache::put('rpt_ctx_1234', ['mode' => 'timeline', 'project' => 'DEMO'], 300);

        $callbackQuery = new CallbackQuery([
            'id' => 'cb_123',
            'data' => CallbackAction::RptTask->value.'DEMO-1',
            'from' => ['id' => 1234],
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => 555, 'type' => 'private'],
            ],
        ]);

        app(TaskManagementService::class)->handleCallback($callbackQuery);
    }

    public function test_open_timeline_menu_renders_options(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isBotAdmin')->andReturn(true);
        $access->shouldReceive('isUserLinked')->andReturn(true);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                $buttons = array_merge(...self::replyRows($extra, 1234));
                $callbacks = array_map(fn ($b) => $b['callback_data'], $buttons);

                // First screen: a cancel leaving the session, no back button.
                return $chatId === 555
                    && in_array(CallbackAction::RptTimeline->value.'status', $callbacks, true)
                    && in_array(CallbackAction::RptTimeline->value.'key', $callbacks, true)
                    && in_array(CallbackAction::RptExit->value, $callbacks, true)
                    && ! in_array(CallbackAction::RptCancel->value, $callbacks, true);
            })
            ->andReturn(new Message(['message_id' => 888]));

        app(TaskManagementService::class)->openTimelineMenu(555, 1234);
    }

    public function test_timeline_option_key_prompts_user_and_sets_pending(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isBotAdmin')->andReturn(true);
        $access->shouldReceive('isUserLinked')->andReturn(true);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('answerCallback')->once();
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                $callbacks = array_map(fn ($b) => $b['callback_data'], array_merge(...self::replyRows($extra, 1234)));

                return $chatId === 555
                    && str_contains($text, __('timeline.menu.enter_key_prompt'))
                    && $callbacks === [CallbackAction::RptCancel->value, CallbackAction::RptExit->value];
            })
            ->andReturn(new Message(['message_id' => 889]));

        $callbackQuery = new CallbackQuery([
            'id' => 'cb_key',
            'data' => CallbackAction::RptTimeline->value.'key',
            'from' => ['id' => 1234],
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => 555, 'type' => 'private'],
            ],
        ]);

        app(TaskManagementService::class)->handleCallback($callbackQuery);

        $this->assertTrue(Cache::has('rpt_timeline_pending_1234'));
    }

    public function test_consume_pending_timeline_key_with_digit_and_pattern(): void
    {
        Cache::put('rpt_timeline_pending_1234', true, 60);

        $taskService = $this->mock(TaskService::class);
        $taskService->shouldReceive('sendTimelineForCommand')
            ->once()
            ->with(555, '32', null, \Mockery::on(fn (array $params): bool => isset($params['reply_markup'])));

        $consumed = app(TaskManagementService::class)->consumePendingText(1234, 555, '32');

        $this->assertTrue($consumed);
        $this->assertFalse(Cache::has('rpt_timeline_pending_1234'));
    }

    public function test_consume_pending_timeline_key_with_persian_digits(): void
    {
        Cache::put('rpt_timeline_pending_1234', true, 60);

        $taskService = $this->mock(TaskService::class);
        $taskService->shouldReceive('sendTimelineForCommand')
            ->once()
            ->with(555, '32', null, \Mockery::on(fn (array $params): bool => isset($params['reply_markup'])));

        $consumed = app(TaskManagementService::class)->consumePendingText(1234, 555, '۳۲');

        $this->assertTrue($consumed);
        $this->assertFalse(Cache::has('rpt_timeline_pending_1234'));
    }

    public function test_task_service_send_timeline_for_command_prefixes_digits_with_default_project(): void
    {
        config(['services.jira.default_project' => 'TS']);

        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('getIssue')
            ->once()
            ->with('TS-32')
            ->andReturn([
                'id' => '10032',
                'key' => 'TS-32',
                'fields' => [
                    'summary' => 'Some task',
                ],
            ]);

        $jira->shouldReceive('getIssueChangelog')
            ->once()
            ->with('TS-32')
            ->andReturn([
                'createdAt' => '2026-03-01T08:00:00.000+0330',
                'createdBy' => 'Reporter',
                'currentStatus' => 'Open',
                'currentAssignee' => null,
                'events' => [],
            ]);

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $chatId, string $text, array $extra): bool {
                return $chatId === 555 && str_contains($text, 'TS-32');
            });

        app(TaskService::class)->sendTimelineForCommand(555, '32');
    }
}
