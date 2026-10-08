<?php

namespace Tests\Unit;

use App\Enums\TaskPriority;
use Tests\TestCase;

class TaskPriorityTest extends TestCase
{
    public function test_backing_values_match_the_jira_default_priority_names(): void
    {
        $this->assertSame('Highest', TaskPriority::Highest->value);
        $this->assertSame('High', TaskPriority::High->value);
        $this->assertSame('Medium', TaskPriority::Medium->value);
        $this->assertSame('Low', TaskPriority::Low->value);
        $this->assertSame('Lowest', TaskPriority::Lowest->value);
    }

    public function test_ordered_returns_all_priorities_in_descending_importance(): void
    {
        $this->assertSame(
            ['Highest', 'High', 'Medium', 'Low', 'Lowest'],
            array_map(fn (TaskPriority $p) => $p->value, TaskPriority::ordered()),
        );
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->assertNull(TaskPriority::tryFrom('Critical'));
        $this->assertNull(TaskPriority::tryFrom('urgent'));
        $this->assertNull(TaskPriority::tryFrom(''));
    }

    public function test_button_label_marks_only_the_selected_priority(): void
    {
        $this->assertSame('✅ High', TaskPriority::High->buttonLabel(true));
        $this->assertSame('High', TaskPriority::High->buttonLabel(false));
    }
}
