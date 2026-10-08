<?php

namespace App\Exceptions;

use RuntimeException;

class JiraTimeoutException extends RuntimeException
{
    public static function fromConnection(string $url, string $detail): self
    {
        return new self("Jira request timed out [{$url}]: {$detail}");
    }
}
