<?php

namespace App\Resources;

use App\Exceptions\JiraTimeoutException;
use App\Resources\Concerns\FormatsRtlText;
use Throwable;

class CommonMessageResource
{
    use FormatsRtlText;

    public static function noProjects(): string
    {
        return self::rtl(__('common.empty.projects'));
    }

    public static function noUsers(): string
    {
        return self::rtl(__('common.empty.users'));
    }

    public static function noBoards(): string
    {
        return self::rtl(__('common.empty.boards'));
    }

    public static function noSprints(): string
    {
        return self::rtl(__('common.empty.sprints'));
    }

    public static function jiraError(): string
    {
        return self::rtl(__('common.error.jira'));
    }

    public static function internalError(): string
    {
        return self::rtl(__('common.error.internal'));
    }

    public static function timeoutError(): string
    {
        return self::rtl(__('common.error.timeout'));
    }

    public static function forThrowable(Throwable $e, ?string $fallback = null): string
    {
        if ($e instanceof JiraTimeoutException) {
            return self::timeoutError();
        }

        return $fallback ?? self::internalError();
    }

    public static function notAuthorizedUser(): string
    {
        return self::rtl(__('common.auth.not_authorized_user'));
    }

    public static function notAuthorizedGroup(): string
    {
        return self::rtl(__('common.auth.not_authorized_group'));
    }

    public static function notLinked(): string
    {
        return self::rtl(__('common.auth.not_linked', [
            'link_command' => self::ltr('/link'),
        ]));
    }

    public static function projectsHeader(): string
    {
        return self::rtl(__('common.header.projects'));
    }

    public static function usersHeader(): string
    {
        return self::rtl(__('common.header.users'));
    }

    public static function boardsHeader(): string
    {
        return self::rtl(__('common.header.boards'));
    }

    public static function sprintsHeader(): string
    {
        return self::rtl(__('common.header.sprints'));
    }

    public static function welcome(string $firstName): string
    {
        return self::rtl(__('common.welcome.greeting', [
            'name' => self::ltr($firstName),
        ]));
    }

    public static function choose(): string
    {
        return self::rtl(__('common.welcome.choose'));
    }

    public static function about(): string
    {
        return self::rtl(__('common.welcome.about'));
    }

    public static function helpText(): string
    {
        return __('common.help.text');
    }
}
