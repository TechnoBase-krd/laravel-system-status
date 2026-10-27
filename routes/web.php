<?php

use Illuminate\Support\Facades\Route;
use Technobase\SystemStatus\Http\Controllers\DeployController;
use Technobase\SystemStatus\Http\Controllers\StatusManagementController;
use Technobase\SystemStatus\Http\Controllers\StatusReportController;
use Technobase\SystemStatus\Http\Controllers\SystemStatusController;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatus;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatusManagement;
use Technobase\SystemStatus\Http\Middleware\EnsureSystemStatusEnabled;

$path = trim((string) config('system-status.path', 'system-status'), '/');

Route::middleware([EnsureSystemStatusEnabled::class, ...(array) config('system-status.middleware', ['web', 'auth']), AuthorizeSystemStatus::class])
    ->group(function () use ($path) {
        Route::get($path, [SystemStatusController::class, 'index'])->name('system_status.index');
        Route::get($path.'/summary', [SystemStatusController::class, 'summary'])->name('system_status.summary');
        Route::get($path.'/components/{component}', [SystemStatusController::class, 'component'])
            ->where('component', '[a-z_]+')
            ->name('system_status.component');
        Route::get($path.'/incidents', [SystemStatusController::class, 'incidents'])->name('system_status.incidents.index');
        Route::get($path.'/security/{event}', [SystemStatusController::class, 'security'])
            ->whereNumber('event')
            ->name('system_status.security.show');
        Route::get($path.'/reports/{month}.{format}', [StatusReportController::class, 'show'])
            ->where(['month' => '\d{4}-\d{2}', 'format' => 'html|csv|pdf'])
            ->name('system_status.report');

        // Posting incidents and scheduling maintenance (system-status.access.manage_gate).
        Route::middleware(AuthorizeSystemStatusManagement::class)->group(function () use ($path) {
            Route::post($path.'/incidents', [StatusManagementController::class, 'storeIncident'])
                ->name('system_status.incidents.store');
            Route::post($path.'/incidents/{incident}/updates', [StatusManagementController::class, 'storeIncidentUpdate'])
                ->whereNumber('incident')
                ->name('system_status.incidents.updates.store');
            Route::patch($path.'/incidents/{incident}', [StatusManagementController::class, 'updateIncident'])
                ->whereNumber('incident')
                ->name('system_status.incidents.update');
            Route::post($path.'/maintenances', [StatusManagementController::class, 'storeMaintenance'])
                ->name('system_status.maintenances.store');
            Route::delete($path.'/maintenances/{maintenance}', [StatusManagementController::class, 'endMaintenance'])
                ->whereNumber('maintenance')
                ->name('system_status.maintenances.end');
        });
    });

// Deploys from CI: a bearer token (system-status.deploys.token), not a session, so no web middleware.
Route::post($path.'/deploys', [DeployController::class, 'store'])
    ->middleware([EnsureSystemStatusEnabled::class, 'throttle:30,1'])
    ->name('system_status.deploys.store');
