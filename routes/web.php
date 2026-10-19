<?php

use Illuminate\Support\Facades\Route;
use Technobase\SystemStatus\Http\Controllers\SystemStatusApiController;
use Technobase\SystemStatus\Http\Controllers\SystemStatusController;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatus;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatusToken;

$path = trim((string) config('system-status.path', 'system-status'), '/');

Route::middleware([...(array) config('system-status.middleware', ['web', 'auth']), AuthorizeSystemStatus::class])
    ->group(function () use ($path) {
        Route::get($path, [SystemStatusController::class, 'index'])->name('system_status.index');
        Route::get($path.'/summary', [SystemStatusController::class, 'summary'])->name('system_status.summary');
        Route::get($path.'/components/{component}', [SystemStatusController::class, 'component'])
            ->where('component', '[a-z_]+')
            ->name('system_status.component');
    });

// JSON API for a central dashboard. Registered only when enabled; guarded by a token.
if (config('system-status.api.enabled', false)) {
    Route::middleware([...(array) config('system-status.api.middleware', ['throttle:60,1']), AuthorizeSystemStatusToken::class])
        ->group(function () use ($path) {
            Route::get($path.'/api/summary', [SystemStatusApiController::class, 'summary'])->name('system_status.api.summary');
            Route::get($path.'/api/components/{component}', [SystemStatusApiController::class, 'component'])
                ->where('component', '[a-z_]+')
                ->name('system_status.api.component');
        });
}
