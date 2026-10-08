<?php

namespace App\Providers;

use App\Contracts\JiraServiceInterface;
use App\Contracts\TelegramMessengerInterface;
use App\Services\Jira\JiraCredentials;
use App\Services\Jira\JiraService;
use App\Services\Telegram\TelegramMessenger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JiraServiceInterface::class, JiraService::class);
        // Whose Jira identity the current request / bot update acts with (see JiraCredentials).
        $this->app->singleton(JiraCredentials::class);
        $this->app->bind(TelegramMessengerInterface::class, TelegramMessenger::class);
    }

    public function boot(): void {}
}
