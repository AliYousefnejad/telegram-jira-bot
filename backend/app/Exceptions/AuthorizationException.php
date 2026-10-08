<?php

namespace App\Exceptions;

use RuntimeException;

class AuthorizationException extends RuntimeException
{
    public static function notAuthorized(int $userId): self
    {
        return new self("User {$userId} is not authorized");
    }

    public static function notLinked(int $userId): self
    {
        return new self("User {$userId} has no linked Jira account");
    }
}
