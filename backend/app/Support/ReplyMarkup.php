<?php

namespace App\Support;

class ReplyMarkup
{
    /**
     * @param  array<string, mixed>  $keyboard
     */
    public static function toJson(array $keyboard): string
    {
        return json_encode($keyboard, JSON_THROW_ON_ERROR);
    }
}
