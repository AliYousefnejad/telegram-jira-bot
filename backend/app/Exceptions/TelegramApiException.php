<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    public static function fromResponse(string $method, int $errorCode, string $description): self
    {
        return new self("Telegram API error [{$method}]: error_code {$errorCode} — {$description}");
    }
}
