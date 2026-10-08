<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Jira answered 401 to a user's own Personal Access Token: it expired or was revoked.
 * The token has already been removed; the user must reconnect or switch to the bot token.
 */
class JiraUserTokenRejected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The personal Jira access token was rejected');
    }
}
