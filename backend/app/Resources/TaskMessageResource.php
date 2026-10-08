<?php

namespace App\Resources;

use App\Resources\Concerns\FormatsRtlText;

class TaskMessageResource
{
    use FormatsRtlText;

    public static function noTasks(string $label): string
    {
        return self::rtl(__('task.list.none', ['label' => self::ltr($label)]));
    }

    public static function tasksHeader(string $projectKey): string
    {
        return self::rtl(__('task.list.header', ['project' => self::ltr($projectKey)]));
    }

    public static function pageHeader(string $projectKey, int $currentPage): string
    {
        return self::rtl(__('task.list.header', ['project' => self::ltr($projectKey)])
            .' — '.self::ltr(__('task.list.page_label').' '.$currentPage));
    }

    public static function taskCreated(string $key, string $summary): string
    {
        return self::rtl(__('task.success.created', [
            'key' => self::ltr($key),
            'summary' => self::ltr($summary),
        ]));
    }

    public static function taskAssigned(string $key, string $name): string
    {
        return self::rtl(__('task.success.assigned', [
            'key' => self::ltr($key),
            'name' => self::ltr($name),
        ]));
    }

    public static function taskUnassigned(string $key): string
    {
        return self::rtl(__('task.success.unassigned', ['key' => self::ltr($key)]));
    }

    public static function sprintAdded(string $key, int $sprintId): string
    {
        return self::rtl(__('task.success.sprint_added', [
            'key' => self::ltr($key),
            'sprint' => self::ltr((string) $sprintId),
        ]));
    }

    public static function statusChangedCallback(string $key): string
    {
        return self::rtl(__('task.success.status_changed', ['key' => self::ltr($key)]));
    }

    public static function assignError(string $key): string
    {
        return self::rtl(__('task.error.assign', ['key' => self::ltr($key)]));
    }

    public static function statusError(string $key): string
    {
        return self::rtl(__('task.error.status', ['key' => self::ltr($key)]));
    }

    public static function sprintError(string $key): string
    {
        return self::rtl(__('task.error.sprint', ['key' => self::ltr($key)]));
    }

    public static function userPickerError(): string
    {
        return self::rtl(__('task.error.users_list'));
    }

    public static function statusPickerError(): string
    {
        return self::rtl(__('task.error.statuses_list'));
    }

    public static function taskNotFound(string $key): string
    {
        return self::rtl(__('task.validation.not_found', ['key' => self::ltr($key)]));
    }

    public static function noTransitions(string $key): string
    {
        return self::rtl(__('task.validation.no_transitions', ['key' => self::ltr($key)]));
    }

    public static function taskKeyRequired(): string
    {
        return self::rtl(__('task.validation.default_project_missing', [
            'example_command' => self::ltr('/task PROJ-123'),
        ]));
    }

    public static function invalidProjectKey(): string
    {
        return self::rtl(__('task.validation.invalid_project_key'));
    }

    public static function userPickerHeader(string $taskKey): string
    {
        return self::rtl(__('task.picker.user_header', ['key' => self::ltr($taskKey)]));
    }

    public static function statusPickerHeader(string $taskKey): string
    {
        return self::rtl(__('task.picker.status_header', ['key' => self::ltr($taskKey)]));
    }

    public static function boardPickerHeader(string $taskKey): string
    {
        return self::rtl(__('task.picker.board_header', ['key' => self::ltr($taskKey)]));
    }

    public static function sprintPickerHeader(string $taskKey): string
    {
        return self::rtl(__('task.picker.sprint_header', ['key' => self::ltr($taskKey)]));
    }
}
