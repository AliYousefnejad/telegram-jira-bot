<?php

namespace Tests\Feature\WebApp;

use App\Enums\TaskOwner;
use App\Models\JiraUser;
use App\Services\Jira\TaskOwnerService;
use App\Services\Jira\TaskService;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Editing a task from the Mini App writes to Jira the same way the bot's Manage tasks does. */
class EditTaskTest extends WebAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->linkAdmin();
    }

    private function edit(array $body, string $key = 'TS-7'): TestResponse
    {
        return $this->postJson("/api/webapp/tasks/{$key}/edits", $body, $this->tmaHeaders($this->telegramUser()));
    }

    #[Test]
    public function the_staged_edits_are_written_to_jira_and_the_fresh_task_comes_back(): void
    {
        $this->mock(TaskOwnerService::class)->shouldReceive('syncOwnerForIssue')->once()->with('TS-7');
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('transitionIssue')->once()->with('TS-7', '31');
            $jira->shouldReceive('assignIssue')->once()->with('TS-7', 'sara');
            $jira->shouldReceive('optionIdForCode')->with('TS-7', 'customfield_sev', 'S1')->andReturn('501');
            $jira->shouldReceive('updateIssueField')->once()->with('TS-7', 'customfield_sev', ['id' => '501']);
            $jira->shouldReceive('addComment')->once()->withArgs(fn (string $key, string $body) => str_contains($body, 'Checked the gateway') && str_contains($body, 'Admin Jira') && ! str_contains($body, '@admin_tg'));
            $jira->shouldReceive('getIssue')->with('TS-7')->andReturn($this->issue('TS-7', 'S1', status: 'Under Triage'));
        });

        $this->edit(['status' => '31', 'assignee' => 'sara', 'severity' => 'S1', 'comment' => 'Checked the gateway'])
            ->assertOk()
            ->assertJson(['results' => ['status' => true, 'assignee' => true, 'severity' => true, 'comment' => true], 'task' => ['key' => 'TS-7', 'status' => 'Under Triage']]);
    }

    #[Test]
    public function a_chosen_owner_wins_over_the_automatic_one(): void
    {
        $this->mock(TaskOwnerService::class)->shouldNotReceive('syncOwnerForIssue');
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('customFieldId')->with('Owner')->andReturn('customfield_owner');
            $jira->shouldReceive('transitionIssue')->once();
            $jira->shouldReceive('optionIdForCode')->with('TS-7', 'customfield_owner', 'L2')->andReturn('77');
            $jira->shouldReceive('updateIssueField')->once()->with('TS-7', 'customfield_owner', ['id' => '77']);
            $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7'));
        });

        $this->edit(['status' => '31', 'owner' => 'L2'])->assertOk()->assertJson(['results' => ['status' => true, 'owner' => true]]);
    }

    #[Test]
    public function one_refused_edit_does_not_stop_the_others(): void
    {
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('assignIssue')->andThrow(new RuntimeException('Jira API returned HTTP 400'));
            $jira->shouldReceive('updateDescription')->once()->with('TS-7', 'A clearer description');
            $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7'));
        });

        $this->edit(['assignee' => 'ghost', 'description' => 'A clearer description'])
            ->assertOk()
            ->assertJson(['results' => ['assignee' => false, 'description' => true]]);
    }

    #[Test]
    public function every_edit_failing_is_an_error(): void
    {
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('addComment')->andThrow(new RuntimeException('down'));
            $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7'));
        });

        $this->edit(['comment' => 'hello there'])->assertStatus(502)->assertJson(['results' => ['comment' => false]]);
    }

    #[Test]
    public function an_empty_assignee_unassigns(): void
    {
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('updateIssueField')->once()->with('TS-7', 'assignee', ['name' => null]);
            $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7'));
        });

        $this->edit(['assignee' => null])->assertOk()->assertJson(['results' => ['assignee' => true]]);
    }

    #[Test]
    public function a_comment_by_a_token_user_has_no_footer(): void
    {
        JiraUser::query()->where('telegram_admin_id', self::ADMIN_ID)->update(['auth_method' => 'pat', 'jira_pat' => encrypt('x', false)]);
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('addComment')->once()->with('TS-7', 'Mine alone');
            $jira->shouldReceive('getIssue')->andReturn($this->issue('TS-7'));
        });

        $this->edit(['comment' => 'Mine alone'])->assertOk();
    }

    #[Test]
    public function edits_are_validated(): void
    {
        $this->edit([])->assertUnprocessable();
        $this->edit(['severity' => 'S9'])->assertUnprocessable()->assertJsonValidationErrors('severity');
        $this->edit(['status' => 'Done'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->edit(['comment' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->edit(['comment' => 'hello'], 'HR-1')->assertUnprocessable()->assertExactJson(['error' => 'invalid_key']);
    }

    #[Test]
    public function the_options_list_reachable_statuses_with_their_owner(): void
    {
        $this->mock(TaskService::class)->shouldReceive('statusOptionsFor')->with('TS-7')->andReturn([['id' => '31', 'name' => 'Under Triage'], ['id' => '41', 'name' => 'Closed']]);
        $this->mock(TaskOwnerService::class)->shouldReceive('resolveOwnerForStatus')->andReturnUsing(fn (string $s) => $s === 'Closed' ? TaskOwner::Reporter : TaskOwner::L1);
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturnNull();
        });

        $this->getJson('/api/webapp/tasks/TS-7/options', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson(['transitions' => [['id' => '31', 'name' => 'Under Triage', 'owner' => 'L1'], ['id' => '41', 'name' => 'Closed', 'owner' => 'Reporter']], 'categories' => null]);
    }
}
