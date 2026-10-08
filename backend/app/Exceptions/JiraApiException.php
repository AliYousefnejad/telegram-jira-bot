<?php

namespace App\Exceptions;

use RuntimeException;

class JiraApiException extends RuntimeException
{
    public static function fromResponse(string $url, int $status, string $body): self
    {
        return new self("Jira API error [{$url}]: HTTP {$status} — {$body}");
    }
}
