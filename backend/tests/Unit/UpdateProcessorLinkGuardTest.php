<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Enums\FlowCommand;
use App\Resources\CommonMessageResource;
use App\Services\AccountLinkService;
use App\Services\Jira\SyncService;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\BotInfoService;
use App\Services\Telegram\CallbackService;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\UpdateProcessor;
use App\Support\CallbackData;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class UpdateProcessorLinkGuardTest extends TestCase
{
    private const CHAT_ID = 555;

    private const USER_ID = 111;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);
        Mockery::close();

        parent::tearDown();
    }

    public function test_unlinked_user_is_blocked_from_a_protected_flow_button(): void
    {
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnFalse();

        $flowReached = false;
        $callbacksReached = false;
        $guardMessageSent = false;

        $this->mock(FlowService::class)
            ->shouldReceive('handleFlowCallback')
            ->andReturnUsing(function () use (&$flowReached): void {
                $flowReached = true;
            });

        $this->mock(CallbackService::class)
            ->shouldReceive('handleCallbackQuery')
            ->andReturnUsing(function () use (&$callbacksReached): void {
                $callbacksReached = true;
            });

        $this->mock(TelegramMessengerInterface::class)
            ->shouldReceive('replyToMessage')
            ->withArgs(function (int $chatId, string $text, int $replyToMessageId) use (&$guardMessageSent): bool {
                $guardMessageSent = $chatId === self::CHAT_ID
                    && $text === CommonMessageResource::notLinked();

                return true;
            });

        $this->processCallback(CallbackData::flow('create'));

        $this->assertTrue($guardMessageSent, 'Unlinked user must receive the not-linked notice.');
        $this->assertFalse($flowReached, 'Protected flows must not start for unlinked users.');
        $this->assertFalse($callbacksReached, 'Task callbacks must not run for unlinked users.');
    }

    public function test_unlinked_user_cannot_reach_task_callbacks(): void
    {
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnFalse();

        $callbacksReached = false;

        $this->mock(CallbackService::class)
            ->shouldReceive('handleCallbackQuery')
            ->andReturnUsing(function () use (&$callbacksReached): void {
                $callbacksReached = true;
            });

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldReceive('replyToMessage');

        $this->processCallback(CallbackAction::PickAssign->value.'DEMO-1_jsmith');

        $this->assertFalse($callbacksReached);
        $messenger->shouldNotHaveReceived('editMessageText');
    }

    public function test_unlinked_user_still_reaches_the_link_flow(): void
    {
        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnFalse();

        $linkFlowStarted = false;

        $this->mock(FlowService::class)
            ->shouldReceive('handleFlowCallback')
            ->withArgs(function (CallbackQuery $query, int $userId) use (&$linkFlowStarted): bool {
                $linkFlowStarted = $userId === self::USER_ID
                    && $query->getData() === CallbackData::flow(FlowCommand::Link->value);

                return true;
            });

        $this->mock(TelegramMessengerInterface::class)->shouldNotReceive('sendMessage');

        $this->processCallback(CallbackData::flow(FlowCommand::Link->value));

        $this->assertTrue($linkFlowStarted, 'The link flow itself must stay reachable.');
    }

    public function test_unlinked_user_can_confirm_the_account_picker_of_the_link_flow(): void
    {
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnFalse();

        $pickerHandled = false;

        $this->mock(AccountLinkService::class)
            ->shouldReceive('handleLinkCallback')
            ->withArgs(function (int $chatId, string $email, string $jiraUserId, int $telegramUserId) use (&$pickerHandled): bool {
                $pickerHandled = $chatId === self::CHAT_ID
                    && $email === 'alice@example.com'
                    && $jiraUserId === 'jsmith'
                    && $telegramUserId === self::USER_ID;

                return true;
            });

        $this->mock(TelegramMessengerInterface::class)->shouldNotReceive('sendMessage');

        $this->processCallback(CallbackData::linkPick('alice@example.com', 'jsmith'));

        $this->assertTrue($pickerHandled, 'Confirming the account picker must complete linking.');
    }

    public function test_unlinked_user_can_still_open_basic_views_from_the_menu(): void
    {
        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnFalse();

        $helpShown = false;

        $this->mock(BotInfoService::class)
            ->shouldReceive('help')
            ->withArgs(function (int $chatId) use (&$helpShown): bool {
                $helpShown = $chatId === self::CHAT_ID;

                return true;
            });

        $this->mock(TelegramMessengerInterface::class)->shouldNotReceive('sendMessage');

        $this->processCallback(CallbackData::flow('help'));

        $this->assertTrue($helpShown, 'Basic views must stay reachable without a linked account.');
    }

    public function test_linked_user_passes_the_guard_and_reaches_the_flows(): void
    {
        $this->allowAnsweringCallbackQuery();
        $this->mock(AccessService::class)->shouldReceive('isUserLinked')->andReturnTrue();

        $this->mock(JiraServiceInterface::class);
        $this->mock(SyncService::class);

        $flowStarted = false;

        $this->mock(FlowService::class)
            ->shouldReceive('handleFlowCallback')
            ->withArgs(function (CallbackQuery $query, int $userId) use (&$flowStarted): bool {
                $flowStarted = $userId === self::USER_ID
                    && $query->getData() === CallbackData::flow('create');

                return true;
            });

        $messenger = $this->mock(TelegramMessengerInterface::class);
        $messenger->shouldNotReceive('sendMessage');

        $this->processCallback(CallbackData::flow('create'));

        $this->assertTrue($flowStarted, 'Linked users must reach the protected flows.');
        $messenger->shouldNotHaveReceived('sendMessage');
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
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cbq-1',
                'from' => ['id' => self::USER_ID, 'first_name' => 'Alice'],
                'message' => [
                    'message_id' => 10,
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                ],
                'data' => $data,
            ],
        ]));
    }
}
