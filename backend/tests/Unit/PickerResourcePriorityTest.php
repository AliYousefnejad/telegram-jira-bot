<?php

namespace Tests\Unit;

use App\Enums\TaskPriority;
use App\Resources\PickerResource;
use Tests\TestCase;

class PickerResourcePriorityTest extends TestCase
{
    public function test_picker_lists_all_priorities_without_marker_or_confirm_when_nothing_is_selected(): void
    {
        $keyboard = PickerResource::priorityPicker('flow_create_priority_', null);

        $rows = $keyboard['inline_keyboard'];
        $this->assertCount(5, $rows);
        $this->assertSame('Highest', $rows[0][0]['text']);
        $this->assertSame('flow_create_priority_Highest', $rows[0][0]['callback_data']);
        $this->assertSame('Lowest', $rows[4][0]['text']);
    }

    public function test_selected_priority_is_marked_and_confirm_row_is_shown(): void
    {
        $keyboard = PickerResource::priorityPicker('flow_create_priority_', TaskPriority::Medium);

        $rows = $keyboard['inline_keyboard'];
        $this->assertSame('✅ Medium', $rows[2][0]['text']);
        $this->assertSame('High', $rows[1][0]['text']);

        $confirmRow = end($rows);
        $this->assertSame(__('priority.confirm'), $confirmRow[0]['text']);
        $this->assertSame('flow_create_priority_confirm', $confirmRow[0]['callback_data']);
    }
}
