<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\FlowCommand;
use App\Resources\FlowMessageResource;
use App\Resources\ReportsMessageResource;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\UpdateProcessor;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class UpdateProcessorReportsMenuTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);
        Cache::forget('interactive_msgs_'.self::USER_ID);
        Mockery::close();

        parent::tearDown();
    }

    public function test_non_admin_clicking_the_reports_menu_is_blocked(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnFalse();

        $this->allowAnsweringCallbackQuery();
        $this->mock(FlowService::class)->shouldNotReceive('startFlow');

        $blocked = false;

        $messages = $this->mock(TelegramMessengerInterface::class);
        $messages->shouldReceive('replyToMessage')
            ->withArgs(function (int $chatId, string $text, int $replyToMessageId, array $extra) use (&$blocked): bool {
                $blocked = $chatId === self::CHAT_ID && $text === ReportsMessageResource::adminOnly();

                return true;
            });

        $this->processCallback('rptmenu_tasks');

        $this->assertTrue($blocked, 'Non-admins must receive the admin-only notice.');
    }

    public function test_admin_manage_tasks_menu_opens_the_project_picker(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnTrue();

        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('getProjectBoard')
            ->once()
            ->with('TS')
            ->andReturn(null)
            ->shouldReceive('getProjectStatuses')
            ->once()
            ->with('TS')
            ->andReturn([['id' => '1', 'name' => 'Open']]);

        $pickerShown = false;

        $messages = $this->mock(TelegramMessengerInterface::class);
        $messages->shouldReceive('send')->never();
        $messages->shouldReceive('sendMessage')
            ->withArgs(function (int $chatId, string $text, array $extra) use (&$pickerShown): bool {
                $pickerShown = $chatId === self::CHAT_ID
                    && str_contains($text, __('reports.manage.filter_label'))
                    && str_contains((string) ($extra['reply_markup'] ?? ''), json_encode(__('reports.manage.all_open')));

                return true;
            });

        $this->processCallback('rptmenu_tasks');

        $this->assertTrue($pickerShown, 'Admins must get the status filter menu.');
    }

    public function test_admin_register_report_menu_starts_the_report_flow(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnTrue();

        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('customFieldId')
            ->andReturn(null);

        $flowStarted = false;

        $flow = $this->mock(FlowService::class);
        $flow->shouldReceive('startFlow')
            ->withArgs(function (int $userId, int $chatId, int $messageId, FlowCommand $command) use (&$flowStarted): bool {
                $flowStarted = $userId === self::USER_ID
                    && $chatId === self::CHAT_ID
                    && $messageId === 10
                    && $command === FlowCommand::Report;

                return true;
            });

        $messages = $this->mock(TelegramMessengerInterface::class);
        $messages->shouldNotReceive('sendMessage');

        $this->processCallback('rptmenu_report');

        $this->assertTrue($flowStarted, 'The report flow must start for admins.');
    }

    public function test_non_admin_report_command_gets_the_admin_only_notice(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnFalse();

        $this->mock(FlowService::class)->shouldNotReceive('startFlow');

        $messages = $this->mock(TelegramMessengerInterface::class);
        $messages->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, ReportsMessageResource::adminOnly(), Mockery::type('array'));

        $this->processMessage('/report');
    }

    public function test_report_command_is_refused_while_a_flow_is_active(): void
    {
        app(ConversationManager::class)->start(FlowCommand::Create, self::USER_ID, self::CHAT_ID, 42, false);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnTrue();

        // The real FlowService runs the idle guard; while busy it must not
        // even look up the custom fields.
        $this->mock(JiraServiceInterface::class)->shouldNotReceive('customFieldId');

        $messages = $this->mock(TelegramMessengerInterface::class);
        $messages->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::alreadyWaiting(), Mockery::type('array'));

        $this->processMessage('/report');

        // The active conversation is untouched.
        $this->assertTrue(app(ConversationManager::class)->isActive(self::USER_ID));
    }

    public function test_create_command_is_refused_while_a_flow_is_active(): void
    {
        // Create task is temporarily disabled — restore this test together
        // with the commented '/create' arm in UpdateProcessor.
        $this->markTestSkipped('Create task is currently disabled.');

        app(ConversationManager::class)->start(FlowCommand::Report, self::USER_ID, self::CHAT_ID, 42, false);

        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnFalse();

        $messages = $this->mock(TelegramMessengerInterface::class);
        $messages->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, FlowMessageResource::alreadyWaiting(), Mockery::type('array'));

        $this->processMessage('/create DEMO "a summary"');

        $context = app(ConversationManager::class)->get(self::USER_ID);
        $this->assertSame('report', $context['command'], 'The running flow must be preserved.');
    }

    private function jiraProjectsPicker(): void
    {
        $this->mock(JiraServiceInterface::class)
            ->shouldReceive('getProjects')
            ->once()
            ->andReturn([
                ['key' => 'DEMO', 'name' => 'Payments'],
            ]);
    }

    private function allowAnsweringCallbackQuery(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('answerCallbackQuery')->andReturnTrue();
        Telegram::swap($api);
    }

    private function processCallback(string $data): void
    {
        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 3,
            'callback_query' => new CallbackQuery([
                'id' => 'cbq-1',
                'from' => ['id' => self::USER_ID],
                'message' => [
                    'message_id' => 10,
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                ],
                'data' => $data,
            ]),
        ]));
    }

    private function processMessage(string $text): void
    {
        app(UpdateProcessor::class)->process(new Update([
            'update_id' => 4,
            'message' => [
                'message_id' => 20,
                'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'text' => $text,
            ],
        ]));
    }
}
