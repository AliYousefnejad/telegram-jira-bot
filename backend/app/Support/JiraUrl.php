<?php

namespace App\Support;

class JiraUrl
{
    public static function browse(string $issueKey): string
    {
        $baseUrl = rtrim((string) config('services.jira.base_url'), '/');

        return $baseUrl.'/browse/'.rawurlencode($issueKey);
    }
}
