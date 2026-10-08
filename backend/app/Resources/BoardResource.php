<?php

namespace App\Resources;

use App\Resources\Concerns\BuildsListText;

class BoardResource
{
    use BuildsListText;

    public static function toList(array $board): string
    {
        return '• ['.($board['id'] ?? '?').'] '.($board['name'] ?? '?').' ('.($board['type'] ?? '?').')';
    }
}
