<?php

namespace Tests\Unit;

use App\Enums\CallbackAction;
use App\Enums\EditAction;
use App\Enums\TaskOwner;
use App\Resources\TaskKeyboardResource;
use Tests\TestCase;

class TaskOwnerTest extends TestCase
{
    public function test_values_and_options(): void
    {
        $this->assertSame(['L1', 'L2', 'Reporter', 'CTO'], TaskOwner::values());

        $options = TaskOwner::options();
        $this->assertArrayHasKey('L1', $options);
        $this->assertArrayHasKey('L2', $options);
        $this->assertArrayHasKey('Reporter', $options);
        $this->assertArrayHasKey('CTO', $options);
    }

    public function test_badges_and_labels(): void
    {
        $this->assertSame('L1', TaskOwner::L1->badge());
        $this->assertSame('L2', TaskOwner::L2->badge());
        $this->assertSame('Reporter', TaskOwner::Reporter->badge());
        $this->assertSame('CTO', TaskOwner::CTO->badge());

        $this->assertSame('L1', TaskOwner::L1->label());
        $this->assertSame('L2', TaskOwner::L2->label());
        $this->assertSame('Reporter', TaskOwner::Reporter->label());
        $this->assertSame('CTO', TaskOwner::CTO->label());
    }

    public function test_from_jira_option(): void
    {
        // Exact matches
        $this->assertSame(TaskOwner::L1, TaskOwner::fromJiraOption('L1'));
        $this->assertSame(TaskOwner::L2, TaskOwner::fromJiraOption('L2'));
        $this->assertSame(TaskOwner::Reporter, TaskOwner::fromJiraOption('Reporter'));
        $this->assertSame(TaskOwner::CTO, TaskOwner::fromJiraOption('CTO'));

        // Case insensitivity
        $this->assertSame(TaskOwner::L1, TaskOwner::fromJiraOption('l1'));
        $this->assertSame(TaskOwner::Reporter, TaskOwner::fromJiraOption('REPORTER'));
        $this->assertSame(TaskOwner::CTO, TaskOwner::fromJiraOption('cto'));

        // Array formats from Jira REST API
        $this->assertSame(TaskOwner::L1, TaskOwner::fromJiraOption(['value' => 'L1']));
        $this->assertSame(TaskOwner::L2, TaskOwner::fromJiraOption([['value' => 'L2']]));

        // Decorated strings
        $this->assertSame(TaskOwner::Reporter, TaskOwner::fromJiraOption('👤 Reporter Team'));
        $this->assertSame(TaskOwner::CTO, TaskOwner::fromJiraOption('CTO / Head'));

        // Null / empty / unknown
        $this->assertNull(TaskOwner::fromJiraOption(null));
        $this->assertNull(TaskOwner::fromJiraOption(''));
        $this->assertNull(TaskOwner::fromJiraOption('Unknown'));
    }

    public function test_task_detail_keyboard_includes_owner_button_in_manage_mode(): void
    {
        $keyboard = TaskKeyboardResource::taskDetail('DEMO-1', manage: true);
        $rows = $keyboard['inline_keyboard'];

        $expectedCallback = CallbackAction::RptAct->value.EditAction::Owner->value.'_DEMO-1';
        $found = false;
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? '') === $expectedCallback) {
                    $found = true;
                    $this->assertSame(__('keyboard.button.owner'), $button['text']);
                }
            }
        }

        $this->assertTrue($found, 'Owner button was not found in manage taskDetail keyboard');
    }

    public function test_staged_edit_menu_includes_owner_button(): void
    {
        $keyboard = TaskKeyboardResource::stagedEditMenu('DEMO-1', []);
        $rows = $keyboard['inline_keyboard'];

        $expectedCallback = CallbackAction::RptAct->value.EditAction::Owner->value.'_DEMO-1';
        $found = false;
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? '') === $expectedCallback) {
                    $found = true;
                    $this->assertSame(__('keyboard.button.owner'), $button['text']);
                }
            }
        }

        $this->assertTrue($found, 'Owner button was not found in stagedEditMenu keyboard');
    }
}
