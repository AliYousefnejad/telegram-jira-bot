<?php

namespace Tests\Unit;

use App\Contracts\JiraServiceInterface;
use App\Enums\ReportType;
use App\Resources\ReportsMessageResource;
use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportsMessageResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fa');
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');
        config(['telegram.reports.timezone' => null]);
        parent::tearDown();
    }

    #[Test]
    public function created_message_shows_the_jalali_date_and_telegram_type_label(): void
    {
        $issue = [
            'key' => 'DEMO-9',
            'fields' => [
                'summary' => ReportType::UserBug->jiraLabel().': 09391234567',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'Ali'],
                'created' => '2026-09-06T12:32:00.000+0000',
            ],
        ];
        $data = ['type' => ReportType::UserBug->value, 'user_name' => '09391234567'];

        $message = ReportsMessageResource::created($issue, $data);

        // The key is shown in the success message.
        $this->assertStringContainsString('🔑', $message);
        $this->assertStringContainsString('DEMO-9', $message);

        // The Jalali line is the genuine Jalali conversion of that instant in
        // Iran time (12:32 UTC → 16:02 Tehran) — not an echo of the stored clock.
        $this->assertStringContainsString('📅 زمان ثبت: ', $message);
        $expectedJalali = Jalalian::fromCarbon(
            Carbon::parse('2026-09-06T12:32:00.000+0000')->timezone('Asia/Tehran')
        )->format('Y/m/d');
        $this->assertStringContainsString($expectedJalali, $message);
        $this->assertStringContainsString('1405/06/15', $message);

        // The type line uses the Telegram-specific label, not the Jira issue type.
        $this->assertStringContainsString('📋 نوع:', $message);
        $this->assertStringContainsString(ReportType::UserBug->telegramType(), $message);
        $this->assertStringNotContainsString('🗓️ زمان ثبت:', $message);
        $this->assertStringNotContainsString('2026-09-06', $message);

        // The owner line displays the resolved owner with crown emoji.
        $this->assertStringContainsString('👑 مالک:', $message);
        $this->assertStringContainsString('L1', $message);

        $this->assertStringNotContainsString('ایجاد در جیرا', $message);
        $this->assertStringContainsString(ReportType::UserBug->badge(), $message);
    }

    #[Test]
    public function created_message_falls_back_when_created_field_is_missing(): void
    {
        $issue = [
            'key' => 'DEMO-11',
            'fields' => [
                'summary' => 'Plain task',
                'issuetype' => ['name' => 'Task'],
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'Ali'],
            ],
        ];
        $data = [];

        $message = ReportsMessageResource::created($issue, $data);

        $this->assertStringContainsString('🔑', $message);
        $this->assertStringContainsString('DEMO-11', $message);
        $this->assertStringContainsString('📋 نوع:', $message);
        $this->assertStringContainsString('Task', $message);
        $this->assertStringContainsString('📅 زمان ثبت:', $message);
        $this->assertStringContainsString('—', $message);
        $this->assertStringNotContainsString('🗓️ زمان ثبت:', $message);
    }

    #[Test]
    public function created_message_places_category_between_status_and_owner_when_present(): void
    {
        $jira = $this->mock(JiraServiceInterface::class);
        $jira->shouldReceive('customFieldId')->with('Ticket Category')->andReturn('customfield_10060');
        $jira->shouldReceive('customFieldId')->with('Owner')->andReturn(null);

        $issue = [
            'key' => 'DEMO-20',
            'fields' => [
                'summary' => ReportType::UserBug->jiraLabel().': 09391234567',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'Ali'],
                'created' => '2026-09-06T12:32:00.000+0000',
                'customfield_10060' => ['value' => 'Payment Gateway'],
            ],
        ];
        $data = ['type' => ReportType::UserBug->value, 'user_name' => '09391234567'];

        $message = ReportsMessageResource::created($issue, $data);

        $this->assertStringContainsString('🟢 وضعیت:', $message);
        $this->assertStringContainsString('Raised', $message);
        $this->assertStringContainsString('📁 دسته‌بندی:', $message);
        $this->assertStringContainsString('Payment Gateway', $message);
        $this->assertStringContainsString('👑 مالک:', $message);

        $statusPos = strpos($message, '🟢 وضعیت:');
        $categoryPos = strpos($message, '📁 دسته‌بندی:');
        $ownerPos = strpos($message, '👑 مالک:');

        $this->assertNotFalse($statusPos);
        $this->assertNotFalse($categoryPos);
        $this->assertNotFalse($ownerPos);

        $this->assertTrue($statusPos < $categoryPos, 'Status must appear before Category');
        $this->assertTrue($categoryPos < $ownerPos, 'Category must appear before Owner');
    }

    #[Test]
    public function created_message_omits_category_when_not_present(): void
    {
        $issue = [
            'key' => 'DEMO-21',
            'fields' => [
                'summary' => ReportType::UserBug->jiraLabel().': 09391234567',
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Raised'],
                'reporter' => ['displayName' => 'Ali'],
                'created' => '2026-09-06T12:32:00.000+0000',
            ],
        ];
        $data = ['type' => ReportType::UserBug->value, 'user_name' => '09391234567'];

        $message = ReportsMessageResource::created($issue, $data);

        $this->assertStringContainsString('🟢 وضعیت:', $message);
        $this->assertStringContainsString('Raised', $message);
        $this->assertStringContainsString('👑 مالک:', $message);
        $this->assertStringNotContainsString('📁 دسته‌بندی:', $message);

        $statusPos = strpos($message, '🟢 وضعیت:');
        $ownerPos = strpos($message, '👑 مالک:');
        $this->assertTrue($statusPos < $ownerPos);
    }
}
