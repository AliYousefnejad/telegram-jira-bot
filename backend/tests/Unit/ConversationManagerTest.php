<?php

namespace Tests\Unit;

use App\Enums\FlowCommand;
use App\Services\Telegram\ConversationManager;
use App\Services\Telegram\FlowDefinition;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;
use Tests\TestCase;

class ConversationManagerTest extends TestCase
{
    private const USER_ID = 111;

    protected function tearDown(): void
    {
        app(ConversationManager::class)->clear(self::USER_ID);

        parent::tearDown();
    }

    public function test_link_flow_prompts_for_email_then_links_account(): void
    {
        $flow = (new ConversationManager(new FlowDefinition))->getFlow('link');

        $this->assertSame('text', $flow[0]['type']);
        $this->assertSame('email', $flow[0]['step']);
        $this->assertSame('flowEnterEmail', $flow[0]['prompt']);
        $this->assertSame('execute', $flow[1]['type']);
        $this->assertArrayNotHasKey('method', $flow[1]);
    }

    public function test_waiting_states_expire_after_five_minutes(): void
    {
        $this->assertSame(5, config('telegram.conversation.ttl_minutes'));
    }

    public function test_start_if_idle_creates_the_waiting_state_when_none_exists(): void
    {
        $manager = app(ConversationManager::class);

        $started = $manager->startIfIdle(FlowCommand::Create, self::USER_ID, 555, 42, false);

        $this->assertTrue($started);
        $this->assertTrue($manager->isActive(self::USER_ID));
        $this->assertSame(FlowCommand::Create->value, $manager->get(self::USER_ID)['command']);
    }

    public function test_start_if_idle_rejects_a_second_start_while_one_is_active(): void
    {
        $manager = app(ConversationManager::class);
        $manager->startIfIdle(FlowCommand::Create, self::USER_ID, 555, 42, false);
        $manager->advance(self::USER_ID, 1, ['summary' => 'keep me']);

        $restarted = $manager->startIfIdle(FlowCommand::Tasks, self::USER_ID, 556, 43, false);

        $this->assertFalse($restarted);
        // The original waiting state was left completely untouched.
        $state = $manager->get(self::USER_ID);
        $this->assertSame(FlowCommand::Create->value, $state['command']);
        $this->assertSame(1, $state['step']);
        $this->assertSame('keep me', $state['data']['summary']);
    }

    public function test_consume_returns_and_removes_the_waiting_state_atomically(): void
    {
        $manager = app(ConversationManager::class);
        $manager->startIfIdle(FlowCommand::Create, self::USER_ID, 555, 42, false);
        $manager->advance(self::USER_ID, 1, ['project' => 'SCRUM']);

        $consumed = $manager->consume(self::USER_ID);

        $this->assertSame('SCRUM', $consumed->data['project']);
        $this->assertFalse($manager->isActive(self::USER_ID));
        $this->assertNull($manager->consume(self::USER_ID));
    }

    public function test_consume_returns_null_when_no_waiting_state_exists(): void
    {
        $this->assertNull(app(ConversationManager::class)->consume(self::USER_ID));
    }

    public function test_tracked_message_deletion_is_chunked_to_the_telegram_limit(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('deleteMessages')
            ->twice()
            ->withArgs(function (array $params): bool {
                static $sizes = [100, 5];

                return current($sizes) === count($params['message_ids'])
                    && array_shift($sizes) === count($params['message_ids']);
            });
        Telegram::swap($api);

        $manager = app(ConversationManager::class);
        $manager->startIfIdle(FlowCommand::Create, self::USER_ID, 555, 42, false);

        for ($i = 1; $i <= 105; $i++) {
            $manager->addTrackedMessage(self::USER_ID, $i);
        }

        $manager->deleteTrackedMessages(555, self::USER_ID);

        // State survives with its tracked messages cleared.
        $this->assertTrue($manager->isActive(self::USER_ID));
        $this->assertSame([], $manager->getTrackedMessages(self::USER_ID));
    }
}
