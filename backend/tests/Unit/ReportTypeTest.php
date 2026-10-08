<?php

namespace Tests\Unit;

use App\Enums\ReportType;
use Tests\TestCase;

class ReportTypeTest extends TestCase
{
    public function test_from_summary_detects_report_built_summaries(): void
    {
        $this->assertSame(
            ReportType::UserBug,
            ReportType::fromSummary(ReportType::UserBug->label().': 09391234567')
        );
        $this->assertSame(
            ReportType::ForceMajeure,
            ReportType::fromSummary(ReportType::ForceMajeure->label())
        );
    }

    public function test_from_summary_detects_plain_jira_summaries(): void
    {
        $this->assertSame(
            ReportType::UserBug,
            ReportType::fromSummary(ReportType::UserBug->jiraLabel().': 09391234567')
        );
        $this->assertSame(
            ReportType::AdminPanelProblem,
            ReportType::fromSummary(ReportType::AdminPanelProblem->jiraLabel())
        );
    }

    public function test_from_summary_returns_null_for_ordinary_tasks(): void
    {
        $this->assertNull(ReportType::fromSummary('Regular task summary'));
    }

    public function test_each_type_has_a_badge(): void
    {
        foreach (ReportType::cases() as $type) {
            $this->assertNotEmpty($type->badge());
        }
    }

    public function test_jira_label_is_emoji_free(): void
    {
        foreach (ReportType::cases() as $type) {
            $this->assertSame(0, preg_match('/[\x{200D}\x{1f300}-\x{1faff}\x{2600}-\x{27bf}\x{fe0f}\x{2b50}]/u', $type->jiraLabel()));
            $this->assertSame(1, preg_match('/[\x{200D}\x{1f300}-\x{1faff}\x{2600}-\x{27bf}\x{fe0f}\x{2b50}]/u', $type->label()));
        }
    }

    public function test_summary_ref_handles_both_prefix_variants(): void
    {
        $this->assertSame(
            ': 09391234567',
            ReportType::summaryRef(ReportType::UserBug->label().': 09391234567')
        );
        $this->assertSame(
            ': 09391234567',
            ReportType::summaryRef(ReportType::UserBug->jiraLabel().': 09391234567')
        );
        $this->assertSame('', ReportType::summaryRef('Plain summary'));
    }
}
