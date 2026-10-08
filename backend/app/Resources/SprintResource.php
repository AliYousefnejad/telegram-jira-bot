<?php

namespace App\Resources;

use App\Resources\Concerns\BuildsListText;

class SprintResource
{
    use BuildsListText;

    public static function toList(array $sprint): string
    {
        return '• ['.($sprint['id'] ?? '?').'] '.($sprint['name'] ?? '?').' ('.($sprint['state'] ?? '?').')';
    }
}
