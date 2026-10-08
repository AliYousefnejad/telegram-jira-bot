<?php

namespace Tests\Unit;

use App\Enums\CallbackAction;
use App\Support\CallbackData;
use Tests\TestCase;

class CallbackDataTest extends TestCase
{
    public function test_callback_actions_carry_the_established_wire_values(): void
    {
        $this->assertSame('noop', CallbackAction::Noop->value);
        $this->assertSame('flow_cancel', CallbackAction::FlowCancel->value);
        $this->assertSame('flow_', CallbackAction::Flow->value);
        $this->assertSame('flowpick_', CallbackAction::FlowPick->value);
        $this->assertSame('page_', CallbackAction::Page->value);
        $this->assertSame('dpage_', CallbackAction::DetailPage->value);
        $this->assertSame('assign_', CallbackAction::Assign->value);
        $this->assertSame('detail_', CallbackAction::Detail->value);
        $this->assertSame('linkpick_', CallbackAction::LinkPick->value);
        $this->assertSame('status_', CallbackAction::Status->value);
        $this->assertSame('push_', CallbackAction::Push->value);
        $this->assertSame('pickboard_', CallbackAction::PickBoard->value);
        $this->assertSame('pickassign_', CallbackAction::PickAssign->value);
        $this->assertSame('pickstatus_', CallbackAction::PickStatus->value);
        $this->assertSame('picksprint_', CallbackAction::PickSprint->value);
    }

    public function test_flow_builders_produce_the_established_wire_format(): void
    {
        $this->assertSame('flow_projects', CallbackData::flow('projects'));
        $this->assertSame('flow_create_task_', CallbackData::flowStepPrefix('create', 'task'));
        $this->assertSame('flowpick_create_project_', CallbackData::flowPickProject('create'));
        $this->assertSame('flow_cancel', CallbackAction::FlowCancel->value);
        $this->assertSame('noop', CallbackAction::Noop->value);
    }

    public function test_picker_builders_produce_the_established_wire_format(): void
    {
        $this->assertSame('assign_DEMO-1', CallbackData::assign('DEMO-1'));
        $this->assertSame('detail_DEMO-1', CallbackData::detail('DEMO-1'));
        $this->assertSame('status_DEMO-1', CallbackData::status('DEMO-1'));
        $this->assertSame('push_DEMO-1', CallbackData::push('DEMO-1'));
        $this->assertSame('linkpick_a@b.com_jsmith', CallbackData::linkPick('a@b.com', 'jsmith'));
        $this->assertSame('pickstatus_DEMO-1_', CallbackData::pickStatusPrefix('DEMO-1'));
        $this->assertSame('pickboard_DEMO-1_', CallbackData::pickBoardPrefix('DEMO-1'));
        $this->assertSame('picksprint_DEMO-1_', CallbackData::pickSprintPrefix('DEMO-1'));
    }

    public function test_split_parses_the_payload_of_a_prefixed_callback(): void
    {
        $this->assertSame(['DEMO', '2'], CallbackData::split('page_DEMO_2', CallbackAction::Page, 2));
        $this->assertSame(['create', 'project', 'DEMO'], CallbackData::split('flowpick_create_project_DEMO', CallbackAction::FlowPick, 3));
    }

    public function test_flow_pick_project_parts_parse_command_and_uppercase_key(): void
    {
        $this->assertSame(
            ['create', 'SCRUM'],
            CallbackData::flowPickProjectParts('flowpick_create_project_SCRUM'),
        );
        $this->assertNull(CallbackData::flowPickProjectParts('flowpick_create_board_B1'));
        $this->assertNull(CallbackData::flowPickProjectParts('noop'));
    }

    public function test_split_returns_null_for_unrelated_or_short_data(): void
    {
        $this->assertNull(CallbackData::split('page_DEMO', CallbackAction::Page, 2));
        $this->assertNull(CallbackData::split('noop', CallbackAction::Page, 2));
        $this->assertNull(CallbackData::split('', CallbackAction::Page, 2));
    }

    public function test_strip_and_starts_with(): void
    {
        $this->assertTrue(CallbackData::startsWith('assign_DEMO-1', CallbackAction::Assign));
        $this->assertFalse(CallbackData::startsWith('noop', CallbackAction::Assign));
        $this->assertSame('DEMO-1', CallbackData::strip('assign_DEMO-1', CallbackAction::Assign));
        $this->assertSame('noop', CallbackData::strip('noop', CallbackAction::Assign));
    }
}
