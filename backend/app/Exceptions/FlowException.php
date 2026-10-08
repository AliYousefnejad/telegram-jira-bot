<?php

namespace App\Exceptions;

use RuntimeException;

class FlowException extends RuntimeException
{
    public static function invalidStep(string $step): self
    {
        return new self("Invalid flow step: {$step}");
    }

    public static function conversationExpired(int $userId): self
    {
        return new self("Conversation expired for user {$userId}");
    }
}
