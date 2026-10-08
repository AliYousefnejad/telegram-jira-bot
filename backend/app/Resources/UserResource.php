<?php

namespace App\Resources;

use App\Resources\Concerns\BuildsListText;
use App\Services\Jira\JiraService;

class UserResource
{
    use BuildsListText;

    public static function toList(array $user): string
    {
        return '• '.($user['displayName'] ?? '—').' ('.($user['emailAddress'] ?? '—').') — ID: '.JiraService::userId($user);
    }
}
