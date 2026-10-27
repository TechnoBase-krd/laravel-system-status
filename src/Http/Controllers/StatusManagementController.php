<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Enums\IncidentState;
use Technobase\SystemStatus\Http\Requests\StoreIncidentRequest;
use Technobase\SystemStatus\Http\Requests\StoreIncidentUpdateRequest;
use Technobase\SystemStatus\Http\Requests\StoreMaintenanceRequest;
use Technobase\SystemStatus\Http\Requests\UpdateIncidentRequest;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Services\IncidentService;
use Technobase\SystemStatus\Services\MaintenanceService;
use Technobase\SystemStatus\Services\SecurityBlocker;

/**
 * Write actions of the page. Access: AuthorizeSystemStatus, then AuthorizeSystemStatusManagement.
 */
class StatusManagementController extends Controller
{
    /**
     * Ends the block of an IP (security.block), also at Cloudflare.
     */
    public function unblock(Request $request, SecurityBlocker $blocker): JsonResponse
    {
        $ip = $request->validate(['ip' => ['required', 'ip']])['ip'];

        return response()->json(['data' => ['ip' => $ip, 'unblocked' => $blocker->unblock($ip)]]);
    }

    public function storeIncident(StoreIncidentRequest $request, IncidentService $service, GetStatusSummaryAction $summary): JsonResponse
    {
        $incident = $service->post(
            $request->validated('component'),
            ComponentStatus::from($request->validated('severity')),
            $request->validated('title'),
            $request->validated('message'),
            IncidentState::from($request->validated('state') ?? IncidentState::Investigating->value),
            self::author($request),
        );

        return response()->json(['data' => $summary->incidentRow($incident->fresh('updates'))], 201);
    }

    public function storeIncidentUpdate(StatusIncident $incident, StoreIncidentUpdateRequest $request, IncidentService $service, GetStatusSummaryAction $summary): JsonResponse
    {
        try {
            $service->update($incident, IncidentState::from($request->validated('state')), $request->validated('message'), self::author($request));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['state' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => $summary->incidentRow($incident->fresh('updates'))], 201);
    }

    public function updateIncident(StatusIncident $incident, UpdateIncidentRequest $request, IncidentService $service, GetStatusSummaryAction $summary): JsonResponse
    {
        try {
            $service->postmortem($incident, $request->validated('postmortem'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['postmortem' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => $summary->incidentRow($incident->fresh('updates'))]);
    }

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
