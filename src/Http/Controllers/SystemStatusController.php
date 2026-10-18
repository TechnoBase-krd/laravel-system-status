<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Technobase\SystemStatus\Actions\GetStatusComponentAction;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Http\Requests\StatusSummaryRequest;
use Technobase\SystemStatus\Support\StatusRegistry;

/**
 * Access is enforced for every route by AuthorizeSystemStatus (system-status.access.roles).
 */
class SystemStatusController extends Controller
{
    public function index(): View
    {
        return view('system-status::index', [
            'styles' => (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/system-status.css'),
        ]);
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
