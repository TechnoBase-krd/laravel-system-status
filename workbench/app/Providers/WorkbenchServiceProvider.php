<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Technobase\SystemStatus\Probes\DiskSpaceProbe;
use Workbench\App\Http\SetDemoLocale;
use Workbench\App\Models\User;

/**
 * The demo app for the status page (screenshots and manual checks). Never loaded in an
 * application: only `vendor/bin/testbench serve` uses it.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'auth.providers.users.model' => User::class,
            'system-status.layout' => 'standalone',
            'system-status.standalone.brand' => 'Demo Shop',
            'system-status.schedule' => false,
            'system-status.record_traffic' => false,
            'system-status.queues' => [],
            'system-status.sla.default_target' => 99.5,
            'system-status.groups' => [
                'platform' => [
                    'database' => ['sla' => 99.9],
                    'redis' => [],
                    'queue' => [],
                    'storage' => ['probes' => [['class' => DiskSpaceProbe::class]]],
                    'scheduler' => [],
                ],
                'mobile_api' => [
                    'api_auth' => ['depends_on' => ['database']],
                    'api_catalogue' => ['depends_on' => ['database', 'redis']],
                    'api_cart_checkout' => ['depends_on' => ['database']],
                    'api_orders' => ['depends_on' => ['database']],
                    'api_other' => [],
                ],
                'backoffice' => [
                    'bo_orders' => [],
                    'bo_products' => [],
                    'bo_reports' => [],
                    'bo_other' => [],
                ],
                'integrations' => [
                    'firebase' => [],
                    'search' => [],
                    'telegram' => [],
                ],
            ],
        ]);
    }

    public function boot(): void
    {
        Route::middleware('web')->get('demo-login', function () {
            Auth::login(User::query()->firstOrCreate(['email' => 'admin@demo.test'], ['name' => 'Demo Admin', 'password' => bcrypt('demo')]));

            return redirect('/'.trim((string) config('system-status.path'), '/').(request('locale') ? '?locale='.request('locale') : ''));
        });

        // ?locale=ar shows the page right to left.
        $this->app['router']->pushMiddlewareToGroup('web', SetDemoLocale::class);
    }
}
