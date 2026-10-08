<?php

namespace App\Models;

use Database\Factories\JiraUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Jira account linked to a Telegram user.
 *
 * @property int $id
 * @property string $display_name
 * @property string $jira_account_id Data Center username (JiraService::userId)
 * @property string|null $email
 * @property int|null $telegram_admin_id
 * @property string|null $telegram_username
 * @property string $auth_method 'email' (acts with the bot's token) or 'pat' (acts as the user)
 * @property string|null $jira_pat the user's own Personal Access Token, encrypted at rest
 * @property Carbon|null $pat_verified_at
 */
class JiraUser extends Model
{
    /** @use HasFactory<JiraUserFactory> */
    use HasFactory;

    public const AUTH_EMAIL = 'email';

    public const AUTH_PAT = 'pat';

    protected $fillable = [
        'display_name',
        'jira_account_id',
        'email',
        'telegram_admin_id',
        'telegram_username',
        'auth_method',
        'jira_pat',
        'pat_verified_at',
    ];

    protected $hidden = ['jira_pat'];

    protected $attributes = ['auth_method' => self::AUTH_EMAIL];

    protected function casts(): array
    {
        return [
            'jira_pat' => 'encrypted',
            'pat_verified_at' => 'datetime',
        ];
    }

    public function hasPersonalToken(): bool
    {
        return $this->auth_method === self::AUTH_PAT && $this->jira_pat !== null && $this->jira_pat !== '';
    }

    /** Signed in with a token that Jira has since rejected: the user must reconnect or switch. */
    public function tokenExpired(): bool
    {
        return $this->auth_method === self::AUTH_PAT && ! $this->hasPersonalToken();
    }
}
