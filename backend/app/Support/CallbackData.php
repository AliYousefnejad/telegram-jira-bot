<?php

namespace App\Support;

use App\Enums\CallbackAction;

class CallbackData
{
    public static function flow(string $command): string
    {
        return self::prefixed(CallbackAction::Flow, $command);
    }

    public static function flowPrefix(string $command): string
    {
        return self::prefixed(CallbackAction::Flow, $command.'_');
    }

    public static function flowStepPrefix(string $command, string $step): string
    {
        return self::flowPrefix($command).$step.'_';
    }

    public static function flowPickProject(string $command): string
    {
        return self::prefixed(CallbackAction::FlowPick, $command.'_project_');
    }

    /** @return array{0: string, 1: string}|null [flow command payload, selected project key] */
    public static function flowPickProjectParts(string $data): ?array
    {
        $parts = self::split($data, CallbackAction::FlowPick, 3);

        if ($parts === null || $parts[1] !== 'project') {
            return null;
        }

        return [$parts[0], strtoupper($parts[2])];
    }

    public static function assign(string $taskKey): string
    {
        return self::prefixed(CallbackAction::Assign, $taskKey);
    }

    public static function detail(string $taskKey): string
    {
        return self::prefixed(CallbackAction::Detail, $taskKey);
    }

    public static function linkPick(string $email, string $userId): string
    {
        return self::prefixed(CallbackAction::LinkPick, $email.'_'.$userId);
    }

    public static function status(string $taskKey): string
    {
        return self::prefixed(CallbackAction::Status, $taskKey);
    }

    public static function push(string $taskKey): string
    {
        return self::prefixed(CallbackAction::Push, $taskKey);
    }

    public static function timeline(string $taskKey): string
    {
        return self::prefixed(CallbackAction::Timeline, $taskKey);
    }

    public static function timelineOption(string $option): string
    {
        return self::prefixed(CallbackAction::RptTimeline, $option);
    }

    public static function pickStatusPrefix(string $taskKey): string
    {
        return self::prefixed(CallbackAction::PickStatus, $taskKey.'_');
    }

    public static function pickBoardPrefix(string $taskKey): string
    {
        return self::prefixed(CallbackAction::PickBoard, $taskKey.'_');
    }

    public static function pickSprintPrefix(string $taskKey): string
    {
        return self::prefixed(CallbackAction::PickSprint, $taskKey.'_');
    }

    public static function startsWith(string $data, CallbackAction $action): bool
    {
        return str_starts_with($data, $action->value);
    }

    /** @return array<int, string>|null null when the data does not start with the prefix */
    public static function split(string $data, CallbackAction $action, int $parts): ?array
    {
        if (! str_starts_with($data, $action->value)) {
            return null;
        }

        $segments = explode('_', substr($data, strlen($action->value)), $parts);

        return count($segments) === $parts ? $segments : null;
    }

    /**
     * Splits linkpick_{email}_{accountId}. Both parts may contain "_", but
     * an email domain never does, so the separator is the first "_" after "@".
     *
     * @return array{0: string, 1: string}|null
     */
    public static function linkPickParts(string $data): ?array
    {
        $payload = self::stripIf($data, CallbackAction::LinkPick);
        $at = $payload !== null ? strpos($payload, '@') : false;
        $separator = $at !== false ? strpos($payload, '_', $at) : false;

        if ($payload === null || $separator === false) {
            return null;
        }

        return [substr($payload, 0, $separator), substr($payload, $separator + 1)];
    }

    public static function strip(string $data, CallbackAction $action): string
    {
        return str_starts_with($data, $action->value) ? substr($data, strlen($action->value)) : $data;
    }

    public static function stripIf(string $data, CallbackAction $action): ?string
    {
        return str_starts_with($data, $action->value)
            ? substr($data, strlen($action->value))
            : null;
    }

    private static function prefixed(CallbackAction $action, string $payload): string
    {
        return $action->value.$payload;
    }
}
