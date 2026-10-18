<?php

use Illuminate\Support\Facades\Route;
use Technobase\SystemStatus\Http\Controllers\SystemStatusController;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatus;

$path = trim((string) config('system-status.path', 'system-status'), '/');

Route::middleware([...(array) config('system-status.middleware', ['web', 'auth']), AuthorizeSystemStatus::class])
    ->group(function () use ($path) {
        Route::get($path, [SystemStatusController::class, 'index'])->name('system_status.index');
        Route::get($path.'/summary', [SystemStatusController::class, 'summary'])->name('system_status.summary');
        Route::get($path.'/components/{component}', [SystemStatusController::class, 'component'])
            ->where('component', '[a-z_]+')
            ->name('system_status.component');
    });
