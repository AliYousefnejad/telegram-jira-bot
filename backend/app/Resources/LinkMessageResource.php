<?php

namespace App\Resources;

use App\Resources\Concerns\FormatsRtlText;

class LinkMessageResource
{
    use FormatsRtlText;

    public static function usageLink(): string
    {
        return self::rtl(__('link.usage', ['command' => self::ltr('/link email@example.com')]));
    }

    public static function alreadyLinked(string $name): string
    {
        return self::rtl(__('link.already_linked', ['name' => self::ltr($name)]));
    }

    public static function linkSuccess(string $name): string
    {
        return self::rtl(self::t('link.success', ['name' => self::ltr($name)])."\n\n".self::t('link.token_hint'));
    }

    public static function duplicate(string $email): string
    {
        return self::rtl(self::t('link.duplicate', ['email' => self::ltr($email)]));
    }

    public static function linkedElsewhere(): string
    {
        return self::rtl(self::t('link.linked_elsewhere'));
    }

    public static function takenOver(string $name): string
    {
        return self::rtl(self::t('link.taken_over', ['name' => self::ltr($name)]));
    }

    public static function tokenRejected(): string
    {
        return self::rtl(self::t('link.token_rejected'));
    }

    public static function linkNotFound(string $email): string
    {
        return self::rtl(__('link.not_found', ['email' => self::ltr($email)]));
    }

    public static function linkError(): string
    {
        return self::rtl(__('link.error'));
    }

    public static function linkPickerHeader(string $email): string
    {
        return self::rtl(__('link.picker_header', ['email' => self::ltr($email)]));
    }

    /** @param  array<string, string>  $replace */
    private static function t(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
