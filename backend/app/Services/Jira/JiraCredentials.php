<?php

namespace App\Services\Jira;

use App\Models\JiraUser;
use Illuminate\Support\Facades\Log;

/**
 * Whose Jira identity the current request or bot update acts with.
 *
 * Users who signed in with their own Personal Access Token act as themselves; everyone else (and
 * system work such as owner sync) uses the bot's shared JIRA_PAT. The bot is one long-running
 * process, so this is set per Mini App request / per Telegram update and cleared afterwards.
 */
class JiraCredentials
{
    private ?JiraUser $user = null;

    private bool $forceBot = false;

    private bool $rejected = false;

    public function actAs(?JiraUser $user): void
    {
        $this->user = $user !== null && $user->hasPersonalToken() ? $user : null;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->forceBot = false;
        $this->rejected = false;
    }

    /**
     * Jira rejected the acting user's token during this request / update. Nothing more is sent to
     * Jira for it (not even with the bot token: the user chooses how to continue).
     */
    public function wasRejected(): bool
    {
        return $this->rejected;
    }

    /** The acting user's own token, or null when the bot's token is used. */
    public function userToken(): ?string
    {
        return $this->forceBot ? null : $this->user?->jira_pat;
    }

    public function isUserToken(): bool
    {
        return $this->userToken() !== null;
    }

    /**
     * Run one bot update as its sender. A user whose token Jira has rejected (now or earlier) is
     * not served with the bot's token behind their back: the work is skipped and true is returned
     * so the caller can tell them to reconnect in TechDesk or switch to the bot token.
     *
     * @param  callable(): mixed  $work
     */
    public function forTelegramUser(int $telegramUserId, callable $work): bool
    {
        $link = $telegramUserId > 0 ? JiraUser::query()->where('telegram_admin_id', $telegramUserId)->first() : null;

        if ($link !== null && $link->tokenExpired()) {
            return true;
        }

        $this->actAs($link);

        try {
            $work();

            return $this->rejected;
        } finally {
            $this->clear();
        }
    }

    /** The user acting with their own token right now, if any. */
    public function actingUser(): ?JiraUser
    {
        return $this->isUserToken() ? $this->user : null;
    }

    /**
     * Run work as a given user (or the bot when null) and restore the current identity afterwards,
     * e.g. for a cache refresh that runs after the response.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function runAs(?JiraUser $user, callable $work): mixed
    {
        $previous = $this->user;
        $this->actAs($user);

        try {
            return $work();
        } finally {
            $this->user = $previous;
        }
    }

    /** Caches of permission-dependent Jira data are kept per identity. */
    public function cacheScope(): string
    {
        return $this->isUserToken() && $this->user !== null
            ? 'u_'.sha1($this->user->jira_account_id)
            : 'bot';
    }

    /**
     * Run system work (owner sync, batch jobs) with the bot's token, whoever is acting.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function asBot(callable $work): mixed
    {
        $previous = $this->forceBot;
        $this->forceBot = true;

        try {
            return $work();
        } finally {
            $this->forceBot = $previous;
        }
    }

    /**
     * Jira refused the acting user's token (expired or revoked): forget it so nothing else is
     * tried with it. The user keeps the link and chooses to reconnect or to use the bot token.
     */
    public function rejectCurrentToken(): void
    {
        if ($this->user === null) {
            return;
        }

        Log::warning('Jira rejected a personal access token; it was removed', ['jira_account_id' => $this->user->jira_account_id]);

        $this->user->forceFill(['jira_pat' => null, 'pat_verified_at' => null])->save();
        $this->user = null;
        $this->rejected = true;
    }
}
