<?php

namespace App\Resources;

use App\Enums\CallbackAction;
use App\Enums\ReportType;
use App\Enums\TaskPriority;
use App\Services\Jira\JiraService;
use App\Support\CallbackData;

class PickerResource
{
    private const SUMMARY_TRUNCATE = 30;

    public static function reportTypePicker(string $prefix): array
    {
        $buttons = array_map(
            fn (ReportType $type): array => [['text' => $type->label(), 'callback_data' => "{$prefix}{$type->value}"]],
            ReportType::cases(),
        );

        return self::withCancelRow($buttons);
    }

    /** @param  array<int, string>  $values @param  array<string, string>|null  $labels value => text */
    public static function optionPicker(array $values, string $prefix, bool $withSkip = false, ?array $labels = null, bool $withCancel = true): array
    {
        $buttons = array_map(
            fn (string $value): array => [['text' => $labels[$value] ?? $value, 'callback_data' => "{$prefix}{$value}"]],
            $values,
        );

        if ($withSkip) {
            $buttons[] = [['text' => __('keyboard.button.skip'), 'callback_data' => $prefix.'skip']];
        }

        return $withCancel ? self::withCancelRow($buttons) : ['inline_keyboard' => $buttons];
    }

    public static function projectPicker(array $projects, string $prefix): array
    {
        $buttons = array_map(
            fn (array $p) => [['text' => ($p['key'] ?? '?').' — '.($p['name'] ?? ''), 'callback_data' => "{$prefix}".($p['key'] ?? '')]],
            $projects
        );

        return self::withCancelRow($buttons);
    }

    public static function taskPicker(array $tasks, string $prefix): array
    {
        $buttons = array_map(
            fn (array $i) => [
                [
                    'text' => ($i['key'] ?? '?').' — '.mb_substr($i['fields']['summary'] ?? '?', 0, self::SUMMARY_TRUNCATE),
                    'callback_data' => "{$prefix}".($i['key'] ?? ''),
                ],
            ],
            $tasks
        );

        return self::withCancelRow($buttons);
    }

    public static function userPicker(array $users, string $prefix, ?string $taskKey = null): array
    {
        $buttons = array_map(
            fn (array $u) => [['text' => $u['displayName'] ?? '?', 'callback_data' => "{$prefix}".($taskKey !== null ? "{$taskKey}_" : '').JiraService::userId($u)]],
            $users
        );

        return self::withCancelRow($buttons);
    }

    public static function statusPicker(array $transitions, string $prefix, bool $withCancel = true): array
    {
        $buttons = array_map(
            fn (array $t) => [['text' => $t['name'] ?? '?', 'callback_data' => "{$prefix}".($t['id'] ?? '')]],
            $transitions
        );

        return $withCancel ? self::withCancelRow($buttons) : ['inline_keyboard' => $buttons];
    }

    public static function boardPicker(array $boards, string $prefix, bool $withCancel = false): array
    {
        $buttons = array_map(
            fn (array $b) => [['text' => $b['name'] ?? '?', 'callback_data' => "{$prefix}".($b['id'] ?? '')]],
            $boards
        );

        return $withCancel ? self::withCancelRow($buttons) : ['inline_keyboard' => $buttons];
    }

    public static function sprintPicker(array $sprints, string $prefix, bool $withCancel = false): array
    {
        $buttons = array_map(
            fn (array $s) => [['text' => ($s['name'] ?? '?').' ('.($s['state'] ?? '?').')', 'callback_data' => "{$prefix}".($s['id'] ?? '')]],
            $sprints
        );

        return $withCancel ? self::withCancelRow($buttons) : ['inline_keyboard' => $buttons];
    }

    public static function linkPicker(array $users, string $email): array
    {
        $buttons = array_map(
            fn (array $u) => [['text' => ($u['displayName'] ?? '?'), 'callback_data' => CallbackData::linkPick($email, JiraService::userId($u))]],
            $users
        );

        $buttons[] = [['text' => __('keyboard.button.cancel'), 'callback_data' => CallbackAction::LinkCancel->value]];

        return ['inline_keyboard' => $buttons];
    }

    /**
     * Back + cancel row closing every flow step after the first one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function flowNavRow(): array
    {
        return [
            ['text' => __('keyboard.button.back'), 'callback_data' => CallbackAction::FlowBack->value],
            ['text' => __('keyboard.button.cancel'), 'callback_data' => CallbackAction::FlowCancel->value],
        ];
    }

    public static function priorityPicker(string $prefix, ?TaskPriority $selected, bool $withSkip = false): array
    {
        $buttons = [];

        foreach (TaskPriority::ordered() as $priority) {
            $buttons[] = [[
                'text' => $priority->buttonLabel($selected === $priority),
                'callback_data' => $prefix.$priority->value,
            ]];
        }

        if ($selected !== null) {
            $buttons[] = [[
                'text' => __('priority.confirm'),
                'callback_data' => $prefix.'confirm',
            ]];
        }

        if ($withSkip && $selected === null) {
            $buttons[] = [['text' => __('keyboard.button.skip'), 'callback_data' => $prefix.'skip']];
        }

        return ['inline_keyboard' => $buttons];
    }

    private static function withCancelRow(array $buttons): array
    {
        $buttons[] = [['text' => __('keyboard.button.cancel'), 'callback_data' => CallbackAction::FlowCancel->value]];

        return ['inline_keyboard' => $buttons];
    }
}
