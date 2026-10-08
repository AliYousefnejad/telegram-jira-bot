<?php

namespace App\Resources;

use App\Enums\CallbackAction;
use App\Enums\EditAction;
use App\Support\CallbackData;

class TaskKeyboardResource
{
    private const SUMMARY_TRUNCATE = 30;

    public static function taskDetail(string $key, bool $manage = false, bool $hasImpactField = false, bool $hasSeverityField = false, bool $hasOwnerField = true, bool $hasCategoryField = false): array
    {
        if ($manage) {
            $rows = [
                [['text' => __('keyboard.button.change_status'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Status->value."_{$key}"]],
                [['text' => __('keyboard.button.assign'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Assignee->value."_{$key}"]],
            ];

            // Priority editing disabled — uncomment to restore.
            // $rows[] = [['text' => __('keyboard.button.priority'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Priority->value."_{$key}"]];

            if ($hasImpactField) {
                $rows[] = [['text' => __('keyboard.button.impact'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Impact->value."_{$key}"]];
            }

            if ($hasSeverityField) {
                $rows[] = [['text' => __('keyboard.button.severity'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Severity->value."_{$key}"]];
            }

            if ($hasCategoryField) {
                $rows[] = [['text' => __('keyboard.button.category'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Category->value."_{$key}"]];
            }

            if ($hasOwnerField) {
                $rows[] = [['text' => __('keyboard.button.owner'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Owner->value."_{$key}"]];
            }

            $rows[] = [['text' => __('keyboard.button.edit_description'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Description->value."_{$key}"]];
            $rows[] = [['text' => __('keyboard.button.add_comment'), 'callback_data' => CallbackAction::RptAct->value.EditAction::Comment->value."_{$key}"]];
            $rows[] = [['text' => __('keyboard.button.task_timeline', ['key' => $key]), 'callback_data' => CallbackData::timeline($key)]];
            $rows[] = [
                ['text' => __('keyboard.button.back'), 'callback_data' => CallbackAction::RptCancelTask->value.$key],
                ['text' => __('keyboard.button.cancel'), 'callback_data' => CallbackAction::RptExit->value],
            ];

            return ['inline_keyboard' => $rows];
        }

        return [
            'inline_keyboard' => [
                [
                    ['text' => __('keyboard.button.assign'), 'callback_data' => CallbackData::assign($key)],
                    ['text' => __('keyboard.button.status'), 'callback_data' => CallbackData::status($key)],
                ],
                [
                    ['text' => __('keyboard.button.sprint'), 'callback_data' => CallbackData::push($key)],
                    ['text' => __('keyboard.button.timeline'), 'callback_data' => CallbackData::timeline($key)],
                ],
            ],
        ];
    }

    /** @param  array<string, string>  $stagedLabels  action => pending display value */
    public static function stagedEditMenu(string $key, array $stagedLabels, bool $hasImpactField = false, bool $hasSeverityField = false, bool $hasOwnerField = true, bool $hasCategoryField = false): array
    {
        $section = fn (string $action, string $text): array => [[
            'text' => isset($stagedLabels[$action])
                ? $text.' — '.$stagedLabels[$action].' ✓'
                : $text,
            'callback_data' => CallbackAction::RptAct->value."{$action}_{$key}",
        ]];

        $rows = [
            $section(EditAction::Status->value, __('keyboard.button.change_status')),
            $section(EditAction::Assignee->value, __('keyboard.button.assign')),
            // Priority editing disabled — uncomment to restore.
            // $section(EditAction::Priority->value, __('keyboard.button.priority')),
        ];

        if ($hasImpactField) {
            $rows[] = $section(EditAction::Impact->value, __('keyboard.button.impact'));
        }

        if ($hasSeverityField) {
            $rows[] = $section(EditAction::Severity->value, __('keyboard.button.severity'));
        }

        if ($hasCategoryField) {
            $rows[] = $section(EditAction::Category->value, __('keyboard.button.category'));
        }

        if ($hasOwnerField) {
            $rows[] = $section(EditAction::Owner->value, __('keyboard.button.owner'));
        }

        $rows[] = $section(EditAction::Description->value, __('keyboard.button.edit_description'));
        $rows[] = $section(EditAction::Comment->value, __('keyboard.button.add_comment'));
        $rows[] = [
            ['text' => __('reports.manage.confirm'), 'callback_data' => CallbackAction::RptConfirm->value.$key],
        ];
        $rows[] = [
            ['text' => __('reports.manage.discard'), 'callback_data' => CallbackAction::RptDiscard->value.$key],
        ];

        return ['inline_keyboard' => $rows];
    }

    public static function buildKeyboard(array $tasks, string $projectKey = '', ?int $prevPage = null, ?int $nextPage = null): array
    {
        $buttons = [];

        $buttons[] = [
            ['text' => __('keyboard.button.assign'), 'callback_data' => CallbackAction::Noop->value],
            ['text' => __('keyboard.button.status'), 'callback_data' => CallbackAction::Noop->value],
        ];

        foreach ($tasks as $task) {
            $key = $task['key'] ?? '';
            if ($key === '') {
                continue;
            }

            $buttons[] = [
                ['text' => $key, 'callback_data' => CallbackData::assign($key)],
                ['text' => $key, 'callback_data' => CallbackData::status($key)],
            ];
        }

        return self::withPagination($buttons, $projectKey, $prevPage, $nextPage, CallbackAction::Page);
    }

    public static function buildDetailKeyboard(array $tasks, string $projectKey = '', ?int $prevPage = null, ?int $nextPage = null): array
    {
        $buttons = [];

        $buttons[] = [
            ['text' => __('keyboard.button.details'), 'callback_data' => CallbackAction::Noop->value],
        ];

        foreach ($tasks as $task) {
            $key = $task['key'] ?? '';
            if ($key === '') {
                continue;
            }

            $summary = mb_substr($task['fields']['summary'] ?? '', 0, self::SUMMARY_TRUNCATE);
            $buttons[] = [
                ['text' => "{$key} — {$summary}", 'callback_data' => CallbackData::detail($key)],
            ];
        }

        return self::withPagination($buttons, $projectKey, $prevPage, $nextPage, CallbackAction::DetailPage);
    }

    public static function buildPaginationKeyboard(string $projectKey, ?int $prevPage, ?int $nextPage, CallbackAction $prefix = CallbackAction::Page): array
    {
        $buttons = [];

        $row = [];
        if ($prevPage !== null) {
            $row[] = ['text' => __('keyboard.button.previous_page'), 'callback_data' => "{$prefix->value}{$projectKey}_{$prevPage}"];
        }
        if ($nextPage !== null) {
            $row[] = ['text' => __('keyboard.button.next_page'), 'callback_data' => "{$prefix->value}{$projectKey}_{$nextPage}"];
        }
        if (! empty($row)) {
            $buttons[] = $row;
        }

        return ['inline_keyboard' => $buttons];
    }

    private static function withPagination(array $buttons, string $projectKey, ?int $prevPage, ?int $nextPage, CallbackAction $prefix): array
    {
        if ($projectKey !== '') {
            $pagination = self::buildPaginationKeyboard($projectKey, $prevPage, $nextPage, $prefix);
            $buttons = array_merge($buttons, $pagination['inline_keyboard'] ?? []);
        }

        return ['inline_keyboard' => $buttons];
    }
}
