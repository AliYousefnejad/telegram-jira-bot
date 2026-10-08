<?php

namespace Tests\Feature\WebApp;

use App\Contracts\TelegramMessengerInterface;
use App\Models\JiraUser;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ShareTaskTest extends WebAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->linkAdmin();
    }

    #[Test]
    public function it_sends_the_task_to_the_users_chat_tagging_the_linked_assignee(): void
    {
        JiraUser::factory()->create(['jira_account_id' => 'sara', 'telegram_username' => 'sara_tg', 'display_name' => 'Sara']);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->with('TS-7')
            ->andReturn($this->issue('TS-7', 'S1', 'I2', 'Under Triage', ['name' => 'sara', 'displayName' => 'Sara'])));

        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage')->once()->withArgs(
            function (int $chatId, string $text, array $extra): bool {
                $this->assertSame(self::ADMIN_ID, $chatId);
                $this->assertSame('HTML', $extra['parse_mode']);
                $this->assertStringContainsString('<b>TS-7</b>', $text);
                $this->assertStringContainsString('Summary of TS-7', $text);
                $this->assertStringContainsString('Sara - ⁦@sara_tg⁩', $text);
                $this->assertStringContainsString('S1 – Critical', $text);
                $this->assertStringContainsString('🔴 شدت', $text);
                $this->assertStringContainsString('🟠 تاثیر', $text);
                $this->assertStringContainsString('🔄', $text);
                $this->assertStringContainsString('https://jira.example.com/browse/TS-7', $text);
                $this->assertStringContainsString('توسط ⁦@admin_tg⁩', $text);
                $this->assertStringNotContainsString('Admin User', $text);

                return true;
            },
        );

        $this->postJson('/api/webapp/tasks/TS-7/share', [], $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson(['sent' => true]);
    }

    #[Test]
    public function a_linked_assignee_without_a_username_is_tagged_by_telegram_id(): void
    {
        $sara = JiraUser::factory()->withoutTelegramUsername()->create(['jira_account_id' => 'sara', 'display_name' => 'Sara']);
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7', assignee: ['name' => 'sara', 'displayName' => 'Sara <x>'])));

        $html = $this->postJson('/api/webapp/tasks/TS-7/share?preview=1', [], $this->tmaHeaders($this->telegramUser()))->assertOk()->json('html');

        $this->assertStringContainsString('<a href="tg://user?id='.$sara->telegram_admin_id.'">Sara &lt;x&gt;</a>', $html);
    }

    #[Test]
    public function an_unlinked_assignee_appears_by_name_only(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7', assignee: ['name' => 'maryam', 'displayName' => 'Maryam'])));

        $html = $this->postJson('/api/webapp/tasks/TS-7/share?preview=1', [], $this->tmaHeaders($this->telegramUser()))->assertOk()->json('html');

        $this->assertStringContainsString('Maryam', $html);
        $this->assertStringNotContainsString('tg://user', $html);
        $this->assertStringNotContainsString('@maryam', $html);
    }

    #[Test]
    public function preview_returns_the_message_without_sending_it(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7')));
        $this->mock(TelegramMessengerInterface::class)->shouldNotReceive('sendMessage');

        $this->postJson('/api/webapp/tasks/TS-7/share?preview=1', [], $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertJsonStructure(['html']);
    }

    #[Test]
    public function a_telegram_failure_is_reported_instead_of_claiming_success(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7')));
        $this->mock(TelegramMessengerInterface::class)->shouldReceive('sendMessage')->andThrow(new RuntimeException('Forbidden: bot was blocked by the user'));

        $this->postJson('/api/webapp/tasks/TS-7/share', [], $this->tmaHeaders($this->telegramUser()))
            ->assertStatus(502)
            ->assertJson(['error' => 'send_failed']);
    }

    #[Test]
    public function sharing_needs_a_valid_task_of_the_project(): void
    {
        $this->mockJira(fn ($jira) => $jira->shouldReceive('getIssue')->with('TS-9')->andReturnNull());
        $this->mock(TelegramMessengerInterface::class)->shouldNotReceive('sendMessage');

        $this->postJson('/api/webapp/tasks/HR-1/share', [], $this->tmaHeaders($this->telegramUser()))->assertUnprocessable();
        $this->postJson('/api/webapp/tasks/TS-9/share', [], $this->tmaHeaders($this->telegramUser()))->assertNotFound();
    }
}
