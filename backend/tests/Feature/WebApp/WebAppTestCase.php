<?php

namespace Tests\Feature\WebApp;

use App\Contracts\JiraServiceInterface;
use App\Models\JiraUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\SignsTelegramInitData;
use Tests\TestCase;

abstract class WebAppTestCase extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected const ADMIN_ID = 700100200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bots.mybot.token' => self::BOT_TOKEN,
            'services.telegram.admin_ids' => [self::ADMIN_ID],
            'services.telegram.webapp_max_age' => 86400,
            'services.jira.default_project' => 'TS',
            'services.jira.base_url' => 'https://jira.example.com',
            'app.locale' => 'fa',
        ]);
    }

    /** @return array<string, mixed> */
    protected function telegramUser(int $id = self::ADMIN_ID, ?string $username = 'admin_tg'): array
    {
        return array_filter(['id' => $id, 'first_name' => 'Admin', 'last_name' => 'User', 'username' => $username], fn ($v) => $v !== null);
    }

    protected function linkAdmin(array $attributes = []): JiraUser
    {
        return JiraUser::factory()->create(array_merge([
            'telegram_admin_id' => self::ADMIN_ID,
            'jira_account_id' => 'admin.jira',
            'display_name' => 'Admin Jira',
            'telegram_username' => 'admin_tg',
        ], $attributes));
    }

    /** A Jira mock whose Severity/Impact custom fields resolve, like a real instance. */
    protected function mockJira(?callable $extra = null): MockInterface
    {
        return $this->mock(JiraServiceInterface::class, function (MockInterface $mock) use ($extra): void {
            $mock->shouldReceive('customFieldId')->with('Severity')->andReturn('customfield_sev');
            $mock->shouldReceive('customFieldId')->with('Impact')->andReturn('customfield_imp');
            $extra && $extra($mock);
        });
    }

    /** @return array<string, mixed> */
    protected function issue(string $key, ?string $severity = null, ?string $impact = null, string $status = 'Raised', ?array $assignee = null, string $updated = '2026-10-01T10:00:00.000+0330'): array
    {
        return [
            'key' => $key,
            'fields' => array_filter([
                'summary' => "Summary of {$key}",
                'description' => "Description of {$key}",
                'status' => ['name' => $status, 'statusCategory' => ['key' => $status === 'Closed' ? 'done' : 'indeterminate']],
                'issuetype' => ['name' => 'Bug'],
                'assignee' => $assignee,
                'created' => '2026-09-06T02:32:00.000+0330',
                'updated' => $updated,
                'customfield_sev' => $severity === null ? null : ['value' => "{$severity} - x"],
                'customfield_imp' => $impact === null ? null : [['value' => "{$impact} - x"]],
            ], fn ($v) => $v !== null),
        ];
    }
}
