<?php

use App\Http\Controllers\WebApp\LinkController;
use App\Http\Controllers\WebApp\ProfileController;
use App\Http\Controllers\WebApp\ReportController;
use App\Http\Controllers\WebApp\TaskController;
use Illuminate\Support\Facades\Route;

// Webhook disabled — the bot receives updates by long polling
// (`php artisan telegram:poll`). Uncomment to restore, then register it with
// the (also commented) `telegram:set-webhook` command.
// use App\Http\Controllers\TelegramController;
// use App\Http\Middleware\RestrictTelegramAccess;
//
// Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])
//     ->middleware(RestrictTelegramAccess::class);

// Telegram Mini App API, authenticated by Telegram's signed launch data.
Route::prefix('webapp')->group(function () {
    Route::get('me', [ProfileController::class, 'me'])->middleware('telegram.webapp');

    // Sign in (email or own Personal Access Token), switch to the bot token, log out.
    Route::middleware('telegram.webapp')->group(function () {
        Route::post('link', [LinkController::class, 'store'])->middleware('throttle:5,10');
        Route::post('link/bot-token', [LinkController::class, 'useBotToken'])->middleware('throttle:10,1');
        Route::delete('link', [LinkController::class, 'destroy'])->middleware('throttle:10,1');
    });

    Route::middleware('telegram.webapp:linked')->group(function () {
        Route::get('me/avatar', [ProfileController::class, 'avatar']);
        Route::get('statuses', [TaskController::class, 'statuses']);
        Route::get('assignees', [TaskController::class, 'assignees']);
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{key}', [TaskController::class, 'show']);
        Route::get('tasks/{key}/options', [TaskController::class, 'options']);
        Route::post('tasks/{key}/edits', [TaskController::class, 'update'])->middleware('throttle:20,1');
        Route::post('tasks/{key}/share', [TaskController::class, 'share'])->middleware('throttle:20,1');
        Route::get('reports/options', [ReportController::class, 'options']);
        Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:10,1');
    });
});
