<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Technobase\SystemStatus\Actions\GetStatusComponentAction;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatusManagement;
use Technobase\SystemStatus\Http\Requests\StatusSummaryRequest;
use Technobase\SystemStatus\Support\StatusRegistry;

/**
 * Access is enforced for every route by AuthorizeSystemStatus (system-status.access.roles).
 */
class SystemStatusController extends Controller
{
    public function index(StatusRegistry $registry): View
    {
        $extra = config('system-status.extra_css');

        $layout = $this->layout();

        return view('system-status::index', [
            'layout' => $layout,
            // The name in the page's description: the brand on the standalone page, else the app's.
            'appName' => $layout === 'system-status::layouts.standalone'
                ? (config('system-status.standalone.brand') ?: 'TechnoBase')
                : config('app.name'),
            'styles' => (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/system-status.css'),
            // Optional branding: a CSS file of the application, added after the package styles.
            'extraStyles' => filled($extra) && is_file($extra) ? (string) file_get_contents($extra) : null,
            'canManage' => AuthorizeSystemStatusManagement::allows(request()->user()),
            // Component choices for the incident and maintenance forms, by group.
            'componentOptions' => collect($registry->groups())->map(fn (array $components, string $group) => [
                'label' => $registry->groupLabel($group),
                'components' => collect(array_keys($components))
                    ->mapWithKeys(fn (string $component) => [$component => $registry->label($component)])
                    ->all(),
            ])->all(),
        ]);
    }

    /**
     * The application's layout when it exists, otherwise the package's own full page.
     */
    private function layout(): string
    {
        $layout = config('system-status.layout', 'layouts.app');

        if (blank($layout) || $layout === 'standalone' || ! view()->exists($layout)) {
            return 'system-status::layouts.standalone';
        }

        return $layout;
    }

    public function summary(StatusSummaryRequest $request, GetStatusSummaryAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->range())]);
    }

    public function component(string $component, StatusRegistry $registry, GetStatusComponentAction $action): JsonResponse
    {
        abort_unless($registry->exists($component), 404);

        return response()->json(['data' => $action->execute($component)]);
    }
}
