<?php

namespace App\Enums;

enum EditAction: string
{
    case Status = 'status';

    case Assignee = 'assignee';

    case Priority = 'priority';

    case Impact = 'impact';

    case Severity = 'severity';

    case Category = 'category';

    case Owner = 'owner';

    case Description = 'description';

    case Comment = 'comment';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $action) => $action->value, self::cases());
    }
}
