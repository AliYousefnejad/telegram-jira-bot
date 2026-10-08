<?php

namespace Tests\Feature\WebApp;

use App\Models\JiraUser;
use App\Services\Telegram\Reports\ReportFlowService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class CreateReportTest extends WebAppTestCase
{
    private const VALID = [
        'type' => 'user_bug',
        'description' => 'پرداخت انجام شده ولی سفارش در انتظار است.',
        'user_name' => 'محمد رضایی',
        'user_identifier' => '84219',
        'user_mobile' => '09123456789',
        'category' => '10401',
        'assignee' => 'sara',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->linkAdmin();
        $this->mockJira(fn ($jira) => $jira->shouldReceive('customFieldId')->byDefault()->andReturnNull());
    }

    #[Test]
    public function it_creates_the_report_in_the_support_project_as_the_signed_in_admin(): void
    {
        $this->mock(ReportFlowService::class)->shouldReceive('createReport')->once()
            ->withArgs(fn (array $data, int $userId) => $data === self::VALID + ['project' => 'TS'] && $userId === self::ADMIN_ID)
            ->andReturn($this->issue('TS-31', 'S2'));

        $this->postJson('/api/webapp/reports', self::VALID, $this->tmaHeaders($this->telegramUser()))
            ->assertCreated()
            ->assertJson(['task' => ['key' => 'TS-31', 'severity' => 'S2']]);
    }

    #[Test]
    public function an_admin_panel_report_needs_no_user_details(): void
    {
        $this->mock(ReportFlowService::class)->shouldReceive('createReport')->once()->andReturn($this->issue('TS-32'));

        $this->postJson('/api/webapp/reports', ['type' => 'admin_panel_problem', 'description' => 'Panel login page returns 500.'], $this->tmaHeaders($this->telegramUser()))
            ->assertCreated();
    }

    #[Test]
    #[DataProvider('invalidReports')]
    public function invalid_reports_are_rejected_before_jira(array $override, string $field): void
    {
        $this->mock(ReportFlowService::class)->shouldNotReceive('createReport');

        $this->postJson('/api/webapp/reports', array_merge(self::VALID, $override), $this->tmaHeaders($this->telegramUser()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidReports(): array
    {
        return [
            'unknown type' => [['type' => 'spam'], 'type'],
            'description too short' => [['description' => 'کوتاه'], 'description'],
            'user bug without a name' => [['user_name' => ''], 'user_name'],
            'user bug without an id' => [['user_identifier' => null], 'user_identifier'],
            'user bug without a mobile' => [['user_mobile' => ''], 'user_mobile'],
            'a category name instead of its option id' => [['category' => 'Website'], 'category'],
        ];
    }

    #[Test]
    public function a_jira_failure_is_reported(): void
    {
        $this->mock(ReportFlowService::class)->shouldReceive('createReport')->andThrow(new RuntimeException('Jira HTTP 400'));

        $this->postJson('/api/webapp/reports', self::VALID, $this->tmaHeaders($this->telegramUser()))
            ->assertStatus(502)
            ->assertJson(['error' => 'jira_failed']);
    }

    #[Test]
    public function an_unlinked_admin_cannot_report(): void
    {
        JiraUser::query()->delete();
        $this->mock(ReportFlowService::class)->shouldNotReceive('createReport');

        $this->postJson('/api/webapp/reports', self::VALID, $this->tmaHeaders($this->telegramUser()))->assertForbidden();
    }

    #[Test]
    public function the_category_options_come_from_the_projects_jira_create_screen(): void
    {
        $this->mockJira(function ($jira): void {
            $jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturn('customfield_cat');
            $jira->shouldReceive('getCreateFieldAllowedOptions')->once()->with('TS', 'customfield_cat', 'Bug')
                ->andReturn([['id' => '10401', 'value' => 'Website'], ['id' => '10402', 'value' => 'Mobile app']]);
        });

        $this->getJson('/api/webapp/reports/options', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson(['categories' => [['id' => '10401', 'value' => 'Website'], ['id' => '10402', 'value' => 'Mobile app']]]);
    }

    #[Test]
    public function there_are_no_category_options_without_a_category_field(): void
    {
        $this->getJson('/api/webapp/reports/options', $this->tmaHeaders($this->telegramUser()))
            ->assertOk()
            ->assertExactJson(['categories' => []]);
    }
}
