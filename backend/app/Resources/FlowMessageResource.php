<?php

namespace App\Resources;

use App\Resources\Concerns\FormatsRtlText;

class FlowMessageResource
{
    use FormatsRtlText;

    public static function flowPickProject(): string
    {
        return self::rtl(__('flow.pick.project'));
    }

    public static function flowPickTask(): string
    {
        return self::rtl(__('flow.pick.task'));
    }

    public static function flowPickUser(): string
    {
        return self::rtl(__('flow.pick.user'));
    }

    public static function flowPickBoard(): string
    {
        return self::rtl(__('flow.pick.board'));
    }

    public static function flowPickSprint(): string
    {
        return self::rtl(__('flow.pick.sprint'));
    }

    public static function flowPickStatus(): string
    {
        return self::rtl(__('flow.pick.status'));
    }

    public static function flowEnterSummary(): string
    {
        return self::rtl(__('flow.enter.summary'));
    }

    public static function flowEnterTaskDescription(): string
    {
        return self::rtl(__('flow.enter.task_description'));
    }

    public static function flowEnterEmail(): string
    {
        return self::rtl(__('flow.enter.email'));
    }

    public static function flowEnterUserName(): string
    {
        return self::rtl(__('reports.enter.user_name'));
    }

    public static function flowEnterUserIdentifier(): string
    {
        return self::rtl(__('reports.enter.user_identifier'));
    }

    public static function flowEnterUserMobile(): string
    {
        return self::rtl(__('reports.enter.user_mobile'));
    }

    public static function flowEnterDescription(): string
    {
        return self::rtl(__('reports.enter.description'));
    }

    public static function flowEnterTopicName(): string
    {
        return self::rtl(__('flow.enter.topic_name'));
    }

    public static function flowCancelled(): string
    {
        return self::rtl(__('flow.cancelled'));
    }

    public static function flowInvalidInput(): string
    {
        return self::rtl(__('flow.invalid_input'));
    }

    public static function alreadyWaiting(): string
    {
        return self::rtl(__('flow.already_waiting'));
    }

    public static function expired(): string
    {
        return self::rtl(__('flow.expired'));
    }
}
