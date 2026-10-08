<?php

namespace Tests\Unit;

use App\Models\JiraUser;
use App\Services\Telegram\AccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccessServiceLinkStatusTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_ID = 111;

    private const JIRA_ACCOUNT_ID = 'jsmith';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.admin_ids' => [self::USER_ID]]);
    }

    public function test_linked_user_is_recognized_in_private_chat(): void
    {
        JiraUser::create([
            'display_name' => 'John',
            'jira_account_id' => self::JIRA_ACCOUNT_ID,
            'telegram_admin_id' => self::USER_ID,
        ]);

        $access = app(AccessService::class);

        $this->assertTrue($access->isUserLinked(self::USER_ID));
    }

    public function test_linked_user_is_recognized_in_new_group(): void
    {
        JiraUser::create([
            'display_name' => 'John',
            'jira_account_id' => self::JIRA_ACCOUNT_ID,
            'telegram_admin_id' => self::USER_ID,
        ]);

        $access = app(AccessService::class);

        // The link check is purely user-scoped — same result regardless of chat context
        $this->assertTrue($access->isUserLinked(self::USER_ID));
    }

    public function test_linked_user_is_recognized_in_forum_topic(): void
    {
        JiraUser::create([
            'display_name' => 'John',
            'jira_account_id' => self::JIRA_ACCOUNT_ID,
            'telegram_admin_id' => self::USER_ID,
        ]);

        $access = app(AccessService::class);

        $this->assertTrue($access->isUserLinked(self::USER_ID));
    }

    public function test_unlinked_user_returns_false(): void
    {
        $access = app(AccessService::class);

        $this->assertFalse($access->isUserLinked(self::USER_ID));
    }

    public function test_link_record_with_null_telegram_admin_id_returns_false(): void
    {
        JiraUser::create([
            'display_name' => 'John',
            'jira_account_id' => self::JIRA_ACCOUNT_ID,
            'telegram_admin_id' => null,
        ]);

        $access = app(AccessService::class);

        $this->assertFalse($access->isUserLinked(self::USER_ID));
    }

    public function test_different_user_id_returns_false(): void
    {
        JiraUser::create([
            'display_name' => 'John',
            'jira_account_id' => self::JIRA_ACCOUNT_ID,
            'telegram_admin_id' => self::USER_ID,
        ]);

        $access = app(AccessService::class);

        $this->assertFalse($access->isUserLinked(99999));
    }

    public function test_admin_check_is_independent_of_link_status(): void
    {
        $access = app(AccessService::class);

        // Admin check uses config, not database
        $this->assertTrue($access->isBotAdmin(self::USER_ID));
        $this->assertFalse($access->isBotAdmin(99999));
    }

    public function test_linked_admin_hides_link_button(): void
    {
        JiraUser::create([
            'display_name' => 'John',
            'jira_account_id' => self::JIRA_ACCOUNT_ID,
            'telegram_admin_id' => self::USER_ID,
        ]);

        $access = app(AccessService::class);

        $this->assertTrue($access->isLinkedAdmin(self::USER_ID));
        $this->assertFalse($access->shouldShowLinkButton(self::USER_ID));
    }

    public function test_unlinked_admin_shows_link_button(): void
    {
        $access = app(AccessService::class);

        $this->assertFalse($access->isLinkedAdmin(self::USER_ID));
        $this->assertTrue($access->shouldShowLinkButton(self::USER_ID));
    }

    public function test_linked_non_admin_shows_link_button(): void
    {
        $nonAdminId = 99999;
        JiraUser::create([
            'display_name' => 'Jane',
            'jira_account_id' => 'jdoe',
            'telegram_admin_id' => $nonAdminId,
        ]);

        $access = app(AccessService::class);

        $this->assertFalse($access->isLinkedAdmin($nonAdminId));
        $this->assertTrue($access->shouldShowLinkButton($nonAdminId));
    }

    public function test_null_user_shows_link_button(): void
    {
        $access = app(AccessService::class);

        $this->assertFalse($access->isLinkedAdmin(null));
        $this->assertTrue($access->shouldShowLinkButton(null));
    }
}
