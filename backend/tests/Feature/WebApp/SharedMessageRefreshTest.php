<?php

namespace Tests\Feature\WebApp;

use App\Contracts\TelegramMessengerInterface;
use App\Models\SharedTaskMessage;
use App\Services\Domain\SharedTaskMessages;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Telegram\Bot\Objects\Message;

/** A task message sent to a chat is edited when the task changes, in the app or in the bot. */
class SharedMessageRefreshTest extends WebAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->linkAdmin();
        $this->withoutDefer();
    }

    #[Test]
    public function a_sent_message_is_remembered(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7')));
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage')->andReturn(new Message(['message_id' => 555]));

        $this->postJson('/api/webapp/tasks/ts-7/share', [], $this->tmaHeaders($this->telegramUser()))->assertOk();

        $this->assertDatabaseHas('shared_task_messages', ['task_key' => 'TS-7', 'chat_id' => self::ADMIN_ID, 'message_id' => 555]);
    }

    #[Test]
    public function editing_the_task_in_the_app_updates_the_sent_messages(): void
    {
        SharedTaskMessage::query()->create(['task_key' => 'TS-7', 'chat_id' => self::ADMIN_ID, 'message_id' => 555, 'shared_by' => $this->telegramUser()]);
        SharedTaskMessage::query()->create(['task_key' => 'TS-8', 'chat_id' => self::ADMIN_ID, 'message_id' => 556, 'shared_by' => $this->telegramUser()]);
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('optionIdForCode')->andReturn('501');
            $jira->shouldReceive('updateIssueField');
            $jira->shouldReceive('getIssue')->with('TS-7')->andReturn($this->issue('TS-7', 'S1'));
        });
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('editMessageText')->once()
            ->withArgs(fn (int $chat, int $message, string $text) => $message === 555 && str_contains($text, '🔴 شدت') && str_contains($text, 'S1 – Critical'));

        $this->postJson('/api/webapp/tasks/TS-7/edits', ['severity' => 'S1'], $this->tmaHeaders($this->telegramUser()))->assertOk();
    }

    #[Test]
    public function a_message_deleted_from_the_chat_is_forgotten(): void
    {
        SharedTaskMessage::query()->create(['task_key' => 'TS-7', 'chat_id' => 1, 'message_id' => 9, 'shared_by' => $this->telegramUser()]);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7')));
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('editMessageText')->andThrow(new RuntimeException('Bad Request: message to edit not found'));

        app(SharedTaskMessages::class)->refresh('TS-7');

        $this->assertDatabaseCount('shared_task_messages', 0);
    }

    #[Test]
    public function nothing_is_fetched_when_no_message_was_sent(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldNotReceive('getIssue'));

        app(SharedTaskMessages::class)->refresh('TS-7');
        $this->addToAssertionCount(1);
    }
}
