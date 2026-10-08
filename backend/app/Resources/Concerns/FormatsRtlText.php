<?php

namespace App\Resources\Concerns;

trait FormatsRtlText
{
    private const RLM = "\xE2\x80\x8F";

    private const LRI = "\xE2\x81\xA6";

    private const PDI = "\xE2\x81\xA9";

    private static function ltr(string $text): string
    {
        return self::LRI.$text.self::PDI;
    }

    private static function rtl(string $text): string
    {
        return self::RLM.$text;
    }
}
