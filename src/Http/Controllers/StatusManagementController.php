<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Http\Requests\StoreMaintenanceRequest;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Services\MaintenanceService;

/**
 * Write actions of the page. Access: AuthorizeSystemStatus, then AuthorizeSystemStatusManagement.
 */
class StatusManagementController extends Controller
{
    public function storeMaintenance(StoreMaintenanceRequest $request, MaintenanceService $service, GetStatusSummaryAction $summary): JsonResponse
    {
        $maintenance = $service->schedule(
            $request->validated('components'),
            self::time($request->validated('starts_at')),
            self::time($request->validated('ends_at')),
            $request->validated('title'),
            $request->validated('message'),
            self::author($request),
        );

        return response()->json(['data' => $summary->maintenanceRow($maintenance)], 201);
    }

    public function endMaintenance(StatusMaintenance $maintenance, MaintenanceService $service): JsonResponse
    {
        $service->end($maintenance);

        return response()->json(['data' => ['ended' => true]]);
    }

    /**
     * A time from the page (ISO 8601 with offset) in the application's timezone, the timezone the
     * other status tables are written in.
     */
    private static function time(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * A display name for the person acting: name, email or id.
     */
    public static function author(Request $request): ?string
    {
        $user = $request->user();

        return $user ? (string) ($user->name ?? $user->email ?? $user->getAuthIdentifier()) : null;
    }
}
