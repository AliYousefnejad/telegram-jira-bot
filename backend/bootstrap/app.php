<?php

use App\Exceptions\JiraUserTokenRejected;
use App\Http\Middleware\RestrictTelegramAccess;
use App\Http\Middleware\ValidateTelegramInitData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'telegram.access' => RestrictTelegramAccess::class,
            'telegram.webapp' => ValidateTelegramInitData::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A user's own Jira token stopped working mid-request: the app asks them to reconnect or
        // switch to the bot token.
        $exceptions->render(fn (JiraUserTokenRejected $e, Request $request) => $request->is('api/*')
            ? response()->json(['error' => 'pat_invalid'], 401)
            : null);
    })->create();
