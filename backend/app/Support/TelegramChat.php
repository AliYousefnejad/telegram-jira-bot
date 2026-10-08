<?php

namespace App\Support;

class TelegramChat
{
    public const GROUP_TYPES = ['group', 'supergroup'];

    public static function isGroup(string $chatType): bool
    {
        return in_array($chatType, self::GROUP_TYPES, true);
    }
}
