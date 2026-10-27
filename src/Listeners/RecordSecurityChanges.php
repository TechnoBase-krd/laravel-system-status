<?php

namespace Technobase\SystemStatus\Listeners;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Technobase\SystemStatus\Services\SecurityMonitor;
use Technobase\SystemStatus\Support\StatusSecurity;
use Throwable;

/**
 * Reports new API tokens (Laravel Sanctum) and role / permission changes
 * (spatie/laravel-permission with permission.events_enabled) as risky actions
 * (security.watch_changes). Registered only while security.enabled is on.
 */
class RecordSecurityChanges
{
    public function __construct(private readonly SecurityMonitor $monitor) {}

    /**
     * eloquent.created: Laravel\Sanctum\PersonalAccessToken
     */
    public function tokenCreated(mixed $token): void
    {
        try {
            $owner = $token instanceof Model ? $token->getRelationValue('tokenable') : null;

            $this->monitor->action('token.created', $this->actor(), array_filter([
                'token' => $token instanceof Model ? (string) $token->getAttribute('name') : null,
                'for' => $owner instanceof Model ? class_basename($owner).' #'.$owner->getKey() : null,
            ]), StatusSecurity::requestIp(), true);
        } catch (Throwable) {
            // Monitoring must never affect the caller.
        }
    }

    /**
     * Spatie\Permission\Events\RoleAttached / RoleDetached / PermissionAttached / PermissionDetached
     */
    public function roleChanged(object $event): void
    {
        try {
            $model = $event->model ?? null;
            $items = $event->rolesOrIds ?? $event->permissionsOrIds ?? null;
            $names = collect(is_iterable($items) ? $items : [$items])
                ->map(fn ($item) => $item instanceof Model ? ($item->getAttribute('name') ?? $item->getKey()) : $item)
                ->filter(fn ($item) => is_scalar($item))
                ->implode(', ');

            $this->monitor->action('roles.'.(str_contains(class_basename($event), 'Detached') ? 'detached' : 'attached'), $this->actor(), array_filter([
                'change' => class_basename($event),
                'target' => $model instanceof Model ? class_basename($model).' #'.$model->getKey() : null,
                'items' => $names !== '' ? $names : null,
            ]), StatusSecurity::requestIp(), true);
        } catch (Throwable) {
            // Monitoring must never affect the caller.
        }
    }

    private function actor(): ?Authenticatable
    {
        try {
            return auth()->user();
        } catch (Throwable) {
            return null;
        }
    }
}
