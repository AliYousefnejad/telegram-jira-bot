<?php

namespace Tests\Feature;

use App\Models\JiraUser;
use App\Resources\CommonMessageResource;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\UpdateProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

/**
 * End-to-end smoke pass over every bot entry point. Each update travels
 * the real dispatch chain (UpdateProcessor → services → JiraService) with
 * Jira HTTP faked and the Telegram API captured, asserting that no entry
 * point produces an internal/Jira/command error or an error-level log.
 *
 * Runs against the configured MySQL connection inside rolled-back
 * transactions (no pdo_sqlite in this environment).
 */
class TelegramSmokeTest extends TestCase
{
    use DatabaseTransactions;

    private const CHAT_ID = 555;

    private const USER_ID = 777;

    private array $sentTexts = [];

    private array $loggedErrors = [];

    private int $nextMessageId = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.admin_ids' => [self::USER_ID]]);

        JiraUser::create([
            'display_name' => 'Alice',
            'jira_account_id' => 'alice',
            'telegram_admin_id' => self::USER_ID,
        ]);

        Event::listen(function (MessageLogged $event): void {
            if ($event->level === 'error') {
                $this->loggedErrors[] = $event->message;
            }
        });

        $this->fakeJira();
        $this->captureTelegram();
    }

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);
        Mockery::close();

        parent::tearDown();
    }

    public function test_every_slash_command_and_button_completes_without_errors(): void
    {
        // --- Info commands & buttons ---
        foreach (['/start', '/help'] as $command) {
            $this->processMessage($command);
        }
        // About / Projects / Boards removed.
        foreach (['start', 'help'] as $payload) {
            $this->processCallback('flow_'.$payload);
        }

        // --- Flow slash commands (idle-guarded engine shared with buttons) ---
        // /status, /unassign, /assign, /push, /sprints, /tasks, /task,
        // /projects and /boards are temporarily disabled.

        // --- Full create conversation: command + step selections ---
        // Create task is temporarily disabled — restore the block below with
        // the commented create flow in FlowDefinition & UpdateProcessor.
        // $this->processMessage('/create');
        // $this->processCallback('flowpick_create_project_DEMO');
        // $this->processMessage('Smoke summary');
        // $this->processMessage('Smoke description body');
        // $this->processCallback('flow_create_priority_High');

        // --- Report flow from the menu button, completed end to end ---
        $this->processCallback('rptmenu_report');
        $this->processCallback('flow_report_type_admin_panel_problem');
        $this->processMessage('Report problem description that is long enough.');

        // --- Task-management section: browse, filter, open, stage, confirm ---
        $this->processCallback('rptmenu_tasks');
        $this->processCallback('rptproj_DEMO');
        $this->processCallback('rptfilter_open');
        $this->processCallback('rpttask_DEMO-1');
        $this->processCallback('rptact_priority_DEMO-1');
        $this->processCallback('rptset_priority_DEMO-1_High');
        $this->processCallback('rptdiscard_DEMO-1');
        $this->processCallback('rptact_status_DEMO-1');
        $this->processCallback('rptset_status_DEMO-1_31');
        $this->processCallback('rptconfirm_DEMO-1');

        // --- Task-detail keyboard callbacks ---
        $this->processCallback('detail_DEMO-1');
        $this->processCallback('assign_DEMO-1');
        $this->processCallback('pickassign_DEMO-1_jsmith');
        $this->processCallback('status_DEMO-1');
        $this->processCallback('pickstatus_DEMO-1_31');
        $this->processCallback('push_DEMO-1');
        $this->processCallback('pickboard_DEMO-1_7');
        $this->processCallback('picksprint_DEMO-1_9');

        // --- Pagination buttons ---
        $this->processCallback('page_DEMO_2');
        $this->processCallback('dpage_DEMO_2');

        // --- The whole pass must be free of user-facing and logged errors ---
        foreach ($this->sentTexts as $text) {
            foreach ([CommonMessageResource::internalError(), CommonMessageResource::jiraError(), __('common.error.command_failed', ['error' => ''])] as $marker) {
                $marker = rtrim((string) $marker, '. ');
                $this->assertStringNotContainsString(mb_substr($marker, 0, 25), $text);
            }
        }

        $this->assertSame([], $this->loggedErrors, 'No workflow may log an error: '.implode(' | ', $this->loggedErrors));
    }

    private function fakeJira(): void
    {
        $issue = fn (string $key): array => [
            'key' => $key,
            'fields' => [
                'summary' => "Summary of {$key}",
                'description' => 'Body',
                'issuetype' => ['id' => '10001', 'name' => 'Task'],
                'priority' => ['name' => 'Medium'],
                'status' => ['name' => 'In Progress'],
                'project' => ['key' => 'DEMO', 'name' => 'Payments', 'id' => '1'],
                'reporter' => ['displayName' => 'Alice'],
                'created' => '2026-08-01T10:00:00.000+0000',
                'updated' => '2026-08-20T10:00:00.000+0000',
            ],
        ];

        Http::fake(function (Request $request) use ($issue) {
            $url = $request->url();

            return Http::response(match (true) {
                str_contains($url, '/rest/api/2/project') => [['key' => 'DEMO', 'name' => 'Payments', 'id' => '1']],
                str_contains($url, '/rest/api/2/search') => [
                    'issues' => [$issue('DEMO-1'), $issue('DEMO-2')],
                    'total' => 12,
                    'startAt' => (int) ($request['startAt'] ?? 0),
                    'maxResults' => 10,
                ],
                str_contains($url, '/user/assignable/search') => [
                    ['name' => 'jsmith', 'displayName' => 'John Smith'],
                    ['name' => 'alice', 'displayName' => 'Alice'],
                ],
                str_contains($url, '/transitions') => [
                    ['id' => '31', 'name' => 'Done', 'to' => ['name' => 'Done']],
                    ['id' => '21', 'name' => 'Progress', 'to' => ['name' => 'In Progress']],
                ],
                str_contains($url, '/board/') && str_contains($url, '/configuration') => [
                    'columnConfig' => ['columns' => [
                        ['name' => 'To Do', 'statuses' => [['id' => '1', 'name' => 'Raised']]],
                        ['name' => 'Done', 'statuses' => [['id' => '10000', 'name' => 'Done']]],
                    ]],
                ],
                str_contains($url, '/board/') && str_contains($url, '/sprint') => ['values' => [['id' => 9, 'name' => 'Sprint 1', 'state' => 'future']]],
                str_contains($url, '/board/') && str_contains($url, 'projectKeyOrId') => ['values' => [['id' => 7]]],
                str_contains($url, '/board/') && preg_match('/board\/\d+$/', $url) => ['location' => ['projectKey' => 'DEMO']],
                str_contains($url, '/rest/agile/1.0/board') => ['values' => [['id' => 7, 'name' => 'DEMO board', 'type' => 'scrum']]],
                str_contains($url, '/filter/') => ['jql' => 'project = DEMO'],
                str_contains($url, '/rest/api/2/status/') && ! str_contains($url, 'project') => ['id' => '10000', 'name' => 'Done'],
                str_contains($url, '/rest/api/2/status') => [
                    ['id' => '1', 'name' => 'Raised'],
                    ['id' => '10000', 'name' => 'Done'],
                ],
                str_contains($url, '/rest/api/2/field') => [],
                str_contains($url, '/groups/picker') => ['groups' => []],
                str_contains($url, '/group/member') => ['values' => []],
                str_contains($url, '/user/search') => [['name' => 'alice', 'displayName' => 'Alice', 'emailAddress' => 'a@b.c']],
                str_contains($url, '/rest/api/2/issue/DEMO-') && $request->method() === 'GET' => $issue(basename(parse_url($url, PHP_URL_PATH))),
                str_contains($url, '/rest/api/2/issue') && $request->method() === 'POST' => ['key' => 'DEMO-9', 'fields' => $issue('DEMO-9')['fields']],
                default => [],
            }, 200);
        });
    }

    private function captureTelegram(): void
    {
        $test = $this;

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        $api->shouldReceive('deleteMessages')->andReturnTrue();
        $api->shouldReceive('sendMessage')->andReturnUsing(function (array $params) use ($test): Message {
            $test->sentTexts[] = (string) ($params['text'] ?? '');

            return new Message(['message_id' => $test->nextMessageId++]);
        });
        $api->shouldReceive('editMessageText')->andReturnUsing(function (array $params) use ($test): Message {
            $test->sentTexts[] = (string) ($params['text'] ?? '');

            return new Message(['message_id' => $test->nextMessageId++]);
        });
        $api->shouldReceive('replyToMessage')->andReturnUsing(function (array $params) use ($test): Message {
            $test->sentTexts[] = (string) ($params['text'] ?? '');

            return new Message(['message_id' => $test->nextMessageId++]);
        });

        Telegram::swap($api);
    }

    private function processMessage(string $text): void
    {
        app(UpdateProcessor::class)->process(new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => $this->nextMessageId++,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'text' => $text,
            ],
        ]));
    }

    private function processCallback(string $data): void
    {
        app(UpdateProcessor::class)->process(new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => new CallbackQuery([
                'id' => 'cbq-'.random_int(1, PHP_INT_MAX),
                'from' => ['id' => self::USER_ID],
                'message' => [
                    'message_id' => $this->nextMessageId++,
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                ],
                'data' => $data,
            ]),
        ]));
    }
}
