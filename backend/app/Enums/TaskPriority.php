<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Highest = 'Highest';

    case High = 'High';

    case Medium = 'Medium';

    case Low = 'Low';

    case Lowest = 'Lowest';

    /** @return array<int, self> */
    public static function ordered(): array
    {
        return [self::Highest, self::High, self::Medium, self::Low, self::Lowest];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::ordered());
    }

    public function buttonLabel(bool $selected): string
    {
        return ($selected ? '✅ ' : '').$this->value;
    }
}
