<?php

namespace App\Resources;

use App\Resources\Concerns\BuildsListText;

class ProjectResource
{
    use BuildsListText;

    public static function toList(array $project): string
    {
        return '• '.($project['key'] ?? '?').' — '.($project['name'] ?? '?');
    }
}
