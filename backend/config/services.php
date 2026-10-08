<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'jira' => [
        'base_url' => env('JIRA_BASE_URL'),
        'pat' => env('JIRA_PAT'),
        'api_token' => env('JIRA_API_TOKEN'),
        'email' => env('JIRA_EMAIL'),
        'project_keys' => array_map('strtoupper', array_filter(array_map('trim', explode(',', env('JIRA_PROJECT_KEYS', ''))))),
        // The support board's project; falls back to the first of JIRA_PROJECT_KEYS.
        'default_project' => strtoupper((string) (env('JIRA_DEFAULT_PROJECT') ?: strtok((string) env('JIRA_PROJECT_KEYS', ''), ', '))),
        'custom_fields' => [
            'impact' => (string) env('JIRA_IMPACT_FIELD', 'Impact'),
            'severity' => (string) env('JIRA_SEVERITY_FIELD', 'Severity'),
            'owner' => (string) env('JIRA_OWNER_FIELD', 'Owner'),
            'category' => (string) env('JIRA_CATEGORY_FIELD', 'Ticket Category'),
        ],
        // Optional: which Owner value a Jira status implies, as JSON, e.g.
        // {"OPEN":"L1","IN PROGRESS":"L1","ESCALATED":"L2","DONE":"Reporter"}. Empty turns owner sync off.
        'status_owner_map' => json_decode((string) env('JIRA_STATUS_OWNER_MAP', ''), true) ?: [],
        'default_issue_type' => (string) env('JIRA_DEFAULT_ISSUE_TYPE', 'Task'),
        'request_timeout_seconds' => (int) env('JIRA_REQUEST_TIMEOUT_SECONDS', 25),
        'connect_timeout_seconds' => (int) env('JIRA_CONNECT_TIMEOUT_SECONDS', 20),
        'retry_delay_us' => (int) env('JIRA_RETRY_DELAY_US', 300_000),
        'cache_ttl_minutes' => (int) env('JIRA_CACHE_TTL_MINUTES', 60),
        'short_cache_ttl_minutes' => (int) env('JIRA_SHORT_CACHE_TTL_MINUTES', 30),
        'max_listed_users' => (int) env('JIRA_MAX_LISTED_USERS', 1000),
        'api_page_size' => (int) env('JIRA_API_PAGE_SIZE', 50),
        'default_max_results' => (int) env('JIRA_DEFAULT_MAX_RESULTS', 20),
    ],

    'telegram' => [
        'admin_ids' => array_filter(array_map('intval', explode(',', env('TELEGRAM_ADMIN_IDS', '')))),
        'allowed_group_ids' => array_filter(array_map('intval', explode(',', env('TELEGRAM_ALLOWED_GROUP_IDS', '')))),
        'webhook_url' => (string) env('TELEGRAM_WEBHOOK_URL', ''),
        // Mini App: how old Telegram's signed launch data may be. The Jira project is jira.default_project.
        'webapp_max_age' => (int) env('TELEGRAM_WEBAPP_MAX_AGE', 86400),
        'webapp_url' => (string) env('TELEGRAM_WEBAPP_URL', ''),
    ],

];
