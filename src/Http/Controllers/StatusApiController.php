<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Composer\InstalledVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Technobase\SystemStatus\Actions\GetStatusComponentAction;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Http\Requests\StatusSummaryRequest;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * Read-only JSON for external monitors (a central dashboard polling many applications).
 * The same data as the page's endpoints, plus a `meta` block, behind system-status.api.token.
 */
class StatusApiController extends Controller
{
    public function summary(StatusSummaryRequest $request, GetStatusSummaryAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->range()), 'meta' => $this->meta()]);
    }

    public function component(string $component, StatusRegistry $registry, GetStatusComponentAction $action): JsonResponse
    {
        abort_unless($registry->exists($component), 404);

        return response()->json(['data' => $action->execute($component), 'meta' => $this->meta()]);
    }

    public function incidents(Request $request, GetStatusSummaryAction $action): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $action->incidentsPage(
            (int) ($validated['page'] ?? 1),
            isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        );

        // The page's pagination meta (page, per_page, total, has_more) plus app, package_version, generated_at.
        $page['meta'] = array_merge((array) ($page['meta'] ?? []), $this->meta());

        return response()->json($page);
    }

    /**
     * @return array{app: string, package_version: string|null, generated_at: string}
     */
    private function meta(): array
    {
        try {
            $version = InstalledVersions::getPrettyVersion('technobase/laravel-system-status');
        } catch (Throwable) {
            $version = null;
        }

        return [
            'app' => (string) config('app.name'),
            'package_version' => $version,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
