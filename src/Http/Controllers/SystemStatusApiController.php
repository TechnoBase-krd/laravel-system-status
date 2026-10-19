<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Technobase\SystemStatus\Actions\GetStatusComponentAction;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Http\Requests\ApiStatusRequest;
use Technobase\SystemStatus\Support\StatusRegistry;

/**
 * The JSON API for a central dashboard that watches several applications. Same data as the
 * page's endpoints, plus an `app` block that says which application answered.
 * Access is enforced by AuthorizeSystemStatusToken (system-status.api.token).
 */
class SystemStatusApiController extends Controller
{
    public function summary(ApiStatusRequest $request, GetStatusSummaryAction $action): JsonResponse
    {
        $request->applyLocale();

        return $this->respond($action->execute($request->range()));
    }

    public function component(ApiStatusRequest $request, string $component, StatusRegistry $registry, GetStatusComponentAction $action): JsonResponse
    {
        $request->applyLocale();

        if (! $registry->exists($component)) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        return $this->respond($action->execute($component));
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json([
            'app' => [
                'name' => config('system-status.api.name') ?: config('app.name'),
                'environment' => app()->environment(),
                'url' => config('app.url'),
            ],
            'generated_at' => now()->toIso8601String(),
            'data' => $data,
        ])->header('Cache-Control', 'no-store');
    }
}
