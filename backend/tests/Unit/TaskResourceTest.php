<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Enums\ReportType;
use App\Resources\TaskResource;
use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;
use Tests\TestCase;

class TaskResourceTest extends TestCase
{
    public function test_manage_list_row_renders_a_report_task_with_status_and_user_ref(): void
    {
        $row = TaskResource::toManageListRow([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => ReportType::UserBug->label().': 09391234567',
                'status' => ['name' => 'Raised'],
            ],
        ]);

        $this->assertSame('• DEMO-1 [Raised] User Issue / Bug 09391234567', $row);
        $this->assertStringNotContainsString(ReportType::UserBug->badge(), $row);
    }

    public function test_manage_list_row_handles_plain_jira_summaries(): void
    {
        $row = TaskResource::toManageListRow([
            'key' => 'DEMO-2',
            'fields' => [
                'summary' => ReportType::UserBug->jiraLabel().': 09391234567',
                'status' => ['name' => 'Raised'],
            ],
        ]);

        $this->assertSame('• DEMO-2 [Raised] User Issue / Bug 09391234567', $row);
        $this->assertStringNotContainsString(ReportType::UserBug->badge(), $row);
    }

    public function test_manage_list_row_renders_a_plain_task(): void
    {
        $row = TaskResource::toManageListRow([
            'key' => 'DEMO-3',
            'fields' => [
                'summary' => 'Regular task summary',
                'issuetype' => ['name' => 'Task'],
                'status' => ['name' => 'Raised'],
            ],
        ]);

        $this->assertSame('• DEMO-3 [Raised] Task Regular task summary', $row);
    }

    public function test_manage_list_row_falls_back_to_a_dash_for_missing_statuses(): void
    {
        $row = TaskResource::toManageListRow([
            'key' => 'DEMO-4',
            'fields' => ['summary' => 'No status here'],
        ]);

        $this->assertSame('• DEMO-4 [—] — No status here', $row);
    }

    public function test_manage_list_row_truncates_long_plain_summaries(): void
    {
        $row = TaskResource::toManageListRow([
            'key' => 'DEMO-5',
            'fields' => [
                'summary' => str_repeat('x', 100),
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
            ],
        ]);

        $this->assertStringNotContainsString(str_repeat('x', 41), $row);
        $this->assertSame('• DEMO-5 [Raised] Bug '.str_repeat('x', 40), $row);
    }

    public function test_detail_renders_the_report_block_without_the_jira_label_prefix(): void
    {
        $text = TaskResource::toDetail([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => ReportType::UserBug->label().': 09391234567',
                'status' => ['name' => 'Raised'],
                'description' => 'h3. Some heading'."\n".'Full problem description',
            ],
        ]);

        $this->assertStringContainsString('🏷️ DEMO-1: 09391234567', $text);
        $this->assertStringContainsString(
            __('task.detail.type', ['value' => ReportType::UserBug->telegramType()]),
            $text
        );
        $this->assertStringContainsString(__('task.detail.report_user', ['value' => '09391234567']), $text);
        $this->assertStringNotContainsString(ReportType::UserBug->label().':', $text);
        $this->assertStringContainsString('Full problem description', $text);
        $this->assertStringNotContainsString('h3.', $text);
    }

    public function test_detail_renders_jalali_created_time(): void
    {
        $text = TaskResource::toDetail([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => 'A plain summary',
                'status' => ['name' => 'Raised'],
                'created' => '2026-09-06T12:32:00.000+0000',
            ],
        ]);

        $this->assertStringNotContainsString('🗓️ زمان ثبت:', $text);

        // The Jalali line is the genuine Jalali conversion of that instant in
        // the configured reports timezone — matching the success message.
        $expectedJalali = Jalalian::fromCarbon(
            Carbon::parse('2026-09-06T12:32:00.000+0000')->timezone('Asia/Tehran')
        )->format('Y/m/d');
        $this->assertStringContainsString(
            __('task.detail.created_jalaali', ['value' => $expectedJalali]),
            $text
        );
        $this->assertStringContainsString('1405/06/15', $text);
    }

    public function test_detail_shows_created_and_updated_in_tehran_jalali_with_24_hour_time(): void
    {
        $text = TaskResource::toDetail([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => 'A plain summary',
                'status' => ['name' => 'Raised'],
                // 11:27 UTC = 14:57 Tehran; 21:00 UTC rolls over to the next Tehran day.
                'created' => '2025-09-21T11:27:00.000+0000',
                'updated' => '2025-09-21T21:00:00.000+0000',
            ],
        ]);

        $this->assertStringContainsString(__('task.detail.created_jalaali', ['value' => '1404/06/30 14:57']), $text);
        $this->assertStringContainsString(__('task.detail.updated', ['value' => '1404/06/31 00:30']), $text);
        $this->assertStringNotContainsString('2025-09-21', $text);
    }

    public function test_detail_dates_fall_back_to_a_dash_when_missing(): void
    {
        $text = TaskResource::toDetail(['key' => 'DEMO-1', 'fields' => ['summary' => 'S', 'status' => ['name' => 'Raised']]]);

        $this->assertStringContainsString(__('task.detail.created_jalaali', ['value' => '—']), $text);
        $this->assertStringContainsString(__('task.detail.updated', ['value' => '—']), $text);
    }

    public function test_detail_uses_emoji_labels(): void
    {
        $text = TaskResource::toDetail([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => 'A plain summary',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
            ],
        ]);

        $this->assertStringContainsString('🟢', $text);
        $this->assertStringContainsString('📋', $text);
    }

    public function test_detail_renders_owner_field(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with('Severity')->andReturn(null);
        $jira->shouldReceive('customFieldId')->with('Impact')->andReturn(null);
        $jira->shouldReceive('customFieldId')->with('Owner')->andReturn('customfield_10050');

        $text = TaskResource::toDetail([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => 'A plain summary',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
                'customfield_10050' => ['value' => 'L1'],
            ],
        ]);

        $this->assertStringContainsString(__('task.detail.owner', ['value' => 'L1']), $text);
    }

    public function test_detail_renders_category_field_only_for_user_bug(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with('Severity')->andReturn(null);
        $jira->shouldReceive('customFieldId')->with('Impact')->andReturn(null);
        $jira->shouldReceive('customFieldId')->with('Owner')->andReturn(null);
        $jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturn('customfield_10060');

        $userBugText = TaskResource::toDetail([
            'key' => 'DEMO-1',
            'fields' => [
                'summary' => ReportType::UserBug->jiraLabel().': Ali',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
                'customfield_10060' => ['value' => 'Payment Gateway'],
            ],
        ]);

        $this->assertStringContainsString(__('task.detail.category', ['value' => 'Payment Gateway']), $userBugText);

        $statusPos = strpos($userBugText, __('task.detail.status', ['value' => 'Raised']));
        $categoryPos = strpos($userBugText, __('task.detail.category', ['value' => 'Payment Gateway']));
        $ownerPos = strpos($userBugText, __('task.detail.owner', ['value' => '—']));

        $this->assertNotFalse($statusPos);
        $this->assertNotFalse($categoryPos);
        $this->assertNotFalse($ownerPos);
        $this->assertTrue($statusPos < $categoryPos, 'Status must appear before Category');
        $this->assertTrue($categoryPos < $ownerPos, 'Category must appear before Owner');

        $plainText = TaskResource::toDetail([
            'key' => 'DEMO-2',
            'fields' => [
                'summary' => 'A plain summary',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
                'customfield_10060' => ['value' => 'Payment Gateway'],
            ],
        ]);

        $this->assertStringNotContainsString(__('task.detail.category', ['value' => 'Payment Gateway']), $plainText);
    }
}
