<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Technobase\SystemStatus\Services\DeployService;

/**
 * POST {path}/deploys from CI: Authorization: Bearer <system-status.deploys.token>.
 * Off (404) while no token is set.
 */
class DeployController extends Controller
{
    public function store(Request $request, DeployService $service): JsonResponse
    {
        $token = (string) config('system-status.deploys.token', '');
        abort_if($token === '', 404);

        $given = (string) ($request->bearerToken() ?? $request->header('X-System-Status-Token', ''));
        abort_unless($given !== '' && hash_equals($token, $given), 401);

        $data = $request->validate([
            'version' => ['required', 'string', 'max:100'],
            'commit' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
            'deployed_by' => ['nullable', 'string', 'max:191'],
            'deployed_at' => ['nullable', 'date'],
        ]);

        $deploy = $service->record(
            $data['version'],
            $data['commit'] ?? null,
            $data['note'] ?? null,
            $data['deployed_by'] ?? null,
            isset($data['deployed_at']) ? Carbon::parse($data['deployed_at']) : null,
        );

        return response()->json(['data' => $service->payload($deploy)], 201);
    }
}
