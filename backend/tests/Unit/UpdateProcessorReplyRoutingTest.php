<?php

namespace Tests\Unit;

use App\Contracts\TelegramMessengerInterface;
use App\Enums\CallbackAction;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\FlowService;
use App\Services\Telegram\ReplyKeyboardService;
use App\Services\Telegram\Reports\TaskManagementService;
use App\Services\Telegram\TopicAccessService;
use App\Services\Telegram\UpdateProcessor;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Update;
use Tests\Concerns\FakesJiraUserLookup;
use Tests\TestCase;

/**
 * Reply keyboard taps arrive as plain text and are replayed as the
 * callback their label stands for.
 */
class UpdateProcessorReplyRoutingTest extends TestCase
{
    use FakesJiraUserLookup;

    private const PRIVATE_CHAT = 555;

    private const GROUP_CHAT = -100123456789;

    private const USER_ID = 111;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.admin_ids' => [self::USER_ID]]);

        $this->fakeJiraUserLookup();
        $this->mock(TopicAccessService::class)->shouldReceive('isTopicAllowed')->andReturnTrue();
        $this->mock(TelegramMessengerInterface::class);
    }

    protected function tearDown(): void
    {
        app(ReplyKeyboardService::class)->forget(self::USER_ID);

        parent::tearDown();
    }

    public function test_tapping_a_flow_button_replays_its_callback(): void
    {
        $this->mockLinkedUser();
        $this->showKeyboard(self::PRIVATE_CHAT, [[['text' => 'User bug', 'callback_data' => 'flow_report_type_user_bug']]]);

        $this->mock(FlowService::class)
            ->shouldReceive('handleFlowCallback')
            ->once()
            ->withArgs(fn (CallbackQuery $query, int $userId): bool => $query->getData() === 'flow_report_type_user_bug'
                && $query->getId() === ReplyKeyboardService::VIRTUAL_CALLBACK_ID
                && $userId === self::USER_ID);
        $this->mock(TaskManagementService::class)->shouldNotReceive('consumePendingText');

        app(UpdateProcessor::class)->process($this->textUpdate('User bug', self::PRIVATE_CHAT, 'private'));
    }

    public function test_tapping_a_task_management_button_in_a_group_reaches_the_session(): void
    {
        $this->mockLinkedUser();
        $this->showKeyboard(self::GROUP_CHAT, [[['text' => 'Open', 'callback_data' => CallbackAction::RptFilter->value.'10306']]]);

        $this->mock(TaskManagementService::class)
            ->shouldReceive('handleCallback')
            ->once()
            ->withArgs(fn (CallbackQuery $query): bool => $query->getData() === 'rptfilter_10306'
                && (int) $query->getMessage()->getChat()->getId() === self::GROUP_CHAT);

        app(UpdateProcessor::class)->process($this->textUpdate('Open', self::GROUP_CHAT, 'supergroup'));
    }

    public function test_a_main_menu_button_leaves_a_pending_prompt_instead_of_being_swallowed(): void
    {
        $this->mockLinkedUser();

        $reports = $this->mock(TaskManagementService::class);
        $reports->shouldNotReceive('consumePendingText');
        $reports->shouldReceive('reset')->once()->with(self::PRIVATE_CHAT, self::USER_ID);
        $reports->shouldReceive('openProjectPicker')->once();

        app(UpdateProcessor::class)->process($this->textUpdate((string) __('keyboard.button.manage_tasks'), self::PRIVATE_CHAT, 'private'));
    }

    public function test_free_text_still_reaches_a_pending_prompt(): void
    {
        $this->mockLinkedUser();
        $this->showKeyboard(self::PRIVATE_CHAT, [[['text' => 'Back', 'callback_data' => CallbackAction::RptCancel->value]]]);

        $this->mock(TaskManagementService::class)
            ->shouldReceive('consumePendingText')
            ->once()
            ->with(self::USER_ID, self::PRIVATE_CHAT, 'Looks like a gateway timeout', null)
            ->andReturnTrue();

        app(UpdateProcessor::class)->process($this->textUpdate('Looks like a gateway timeout', self::PRIVATE_CHAT, 'private'));
    }

    public function test_an_unlinked_user_can_cancel_the_link_flow_from_the_keyboard(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnFalse();
        $this->showKeyboard(self::PRIVATE_CHAT, [[['text' => 'Cancel', 'callback_data' => CallbackAction::FlowCancel->value]]]);

        $this->mock(FlowService::class)
            ->shouldReceive('handleFlowCallback')
            ->once()
            ->withArgs(fn (CallbackQuery $query): bool => $query->getData() === CallbackAction::FlowCancel->value);

        app(UpdateProcessor::class)->process($this->textUpdate('Cancel', self::PRIVATE_CHAT, 'private'));
    }

    private function mockLinkedUser(): void
    {
        $access = $this->mock(AccessService::class);
        $access->shouldReceive('isUserLinked')->andReturnTrue();
        $access->shouldReceive('isBotAdmin')->andReturnTrue();
    }

    /** @param  array<int, array<int, array<string, string>>>  $rows */
    private function showKeyboard(int $chatId, array $rows): void
    {
        app(ReplyKeyboardService::class)->extra($rows, self::USER_ID, $chatId);
    }

    private function textUpdate(string $text, int $chatId, string $chatType): Update
    {
        return new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => 42,
                'text' => $text,
                'chat' => ['id' => $chatId, 'type' => $chatType],
                'from' => ['id' => self::USER_ID, 'first_name' => 'Ali', 'username' => 'ali_dev'],
            ],
        ]);
    }
}
