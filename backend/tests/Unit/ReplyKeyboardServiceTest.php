<?php

namespace Tests\Unit;

use App\Enums\CallbackAction;
use App\Services\Telegram\AccessService;
use App\Services\Telegram\ReplyKeyboardService;
use Tests\TestCase;

class ReplyKeyboardServiceTest extends TestCase
{
    private const USER_ID = 111;

    private const PRIVATE_CHAT = 555;

    private const GROUP_CHAT = -100123;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AccessService::class);
    }

    protected function tearDown(): void
    {
        app(ReplyKeyboardService::class)->forget(self::USER_ID);

        parent::tearDown();
    }

    public function test_inline_rows_become_reply_buttons_resolving_to_their_actions(): void
    {
        $service = app(ReplyKeyboardService::class);

        $extra = $service->extra(['inline_keyboard' => [
            [['text' => 'Open', 'callback_data' => 'rptfilter_1'], ['text' => 'Closed', 'callback_data' => 'rptfilter_2']],
            [['text' => '───', 'callback_data' => CallbackAction::Noop->value]],
            [['text' => 'Cancel', 'callback_data' => 'rptexit']],
        ]], self::USER_ID, self::PRIVATE_CHAT);

        $markup = json_decode($extra['reply_markup'], true);

        $this->assertSame([[['text' => 'Open'], ['text' => 'Closed']], [['text' => 'Cancel']]], $markup['keyboard']);
        $this->assertTrue($markup['resize_keyboard']);
        $this->assertArrayNotHasKey('selective', $markup);
        $this->assertArrayNotHasKey('reply_parameters', $extra);
        $this->assertSame('rptfilter_2', $service->resolve(self::USER_ID, 'Closed'));
        $this->assertSame('rptexit', $service->resolve(self::USER_ID, 'Cancel'));
        $this->assertNull($service->resolve(self::USER_ID, '───'));
        $this->assertNull($service->resolve(self::USER_ID, 'free text'));
    }

    public function test_duplicate_labels_are_made_unique_and_long_labels_are_trimmed(): void
    {
        $service = app(ReplyKeyboardService::class);
        $long = str_repeat('گزارش ', 30);

        $extra = $service->extra([
            [['text' => 'John Smith', 'callback_data' => 'rptset_assignee_TS-1_a']],
            [['text' => 'John Smith', 'callback_data' => 'rptset_assignee_TS-1_b']],
            [['text' => $long, 'callback_data' => 'rpttask_TS-9']],
        ], self::USER_ID, self::PRIVATE_CHAT);

        $labels = array_column(array_merge(...json_decode($extra['reply_markup'], true)['keyboard']), 'text');

        $this->assertSame(['John Smith', 'John Smith (2)'], array_slice($labels, 0, 2));
        $this->assertSame('rptset_assignee_TS-1_b', $service->resolve(self::USER_ID, 'John Smith (2)'));
        $this->assertLessThanOrEqual(60, mb_strlen($labels[2]));
        $this->assertSame('rpttask_TS-9', $service->resolve(self::USER_ID, $labels[2]));
    }

    public function test_group_keyboards_are_selective_replies_to_the_users_last_message(): void
    {
        $service = app(ReplyKeyboardService::class);
        $service->rememberUserMessage(self::USER_ID, self::GROUP_CHAT, 42);

        $extra = $service->extra([[['text' => 'Go', 'callback_data' => 'rptexit']]], self::USER_ID, self::GROUP_CHAT);

        $this->assertTrue(json_decode($extra['reply_markup'], true)['selective']);
        $this->assertSame(
            ['message_id' => 42, 'allow_sending_without_reply' => true],
            json_decode($extra['reply_parameters'], true),
        );
    }

    public function test_main_menu_drops_the_flow_map_but_keeps_rows_shown_above_it(): void
    {
        $service = app(ReplyKeyboardService::class);
        $service->extra([[['text' => 'Old', 'callback_data' => 'rptexit']]], self::USER_ID, self::PRIVATE_CHAT);

        $extra = $service->mainMenu(self::USER_ID, self::PRIVATE_CHAT, [
            [['text' => 'Timeline TS-5', 'callback_data' => 'timeline_TS-5']],
        ]);

        $labels = array_column(array_merge(...json_decode($extra['reply_markup'], true)['keyboard']), 'text');

        $this->assertSame('Timeline TS-5', $labels[0]);
        $this->assertContains(__('keyboard.button.register_report'), $labels);
        $this->assertNull($service->resolve(self::USER_ID, 'Old'));
        $this->assertSame('timeline_TS-5', $service->resolve(self::USER_ID, 'Timeline TS-5'));

        $service->mainMenu(self::USER_ID, self::PRIVATE_CHAT);

        $this->assertNull($service->resolve(self::USER_ID, 'Timeline TS-5'));
    }
}
