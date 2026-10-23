<?php

namespace Technobase\SystemStatus\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Enums\IncidentState;
use Technobase\SystemStatus\Events\IncidentUpdated;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusIncidentUpdate;
use Technobase\SystemStatus\Support\StatusAlerter;
use Technobase\SystemStatus\Support\StatusDuration;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * Incidents posted by hand, timeline updates and postmortems. A manual incident holds its
 * component at its severity until someone resolves it.
 */
class IncidentService
{
    public function __construct(
        private readonly StatusRegistry $registry,
        private readonly StatusAlerter $alerter,
    ) {}

    public function post(
        string $component,
        ComponentStatus $severity,
        string $title,
        ?string $message = null,
        IncidentState $state = IncidentState::Investigating,
        ?string $author = null,
    ): StatusIncident {
        if (! $this->registry->exists($component)) {
            throw new InvalidArgumentException("Unknown component [{$component}].");
        }

        if (! $severity->isProblem()) {
            throw new InvalidArgumentException('An incident is degraded or a major outage.');
        }

        if ($state === IncidentState::Resolved) {
            throw new InvalidArgumentException('A new incident cannot start resolved.');
        }

        $incident = DB::transaction(function () use ($component, $severity, $title, $message, $state, $author) {
            $incident = StatusIncident::query()->create([
                'component' => $component,
                'severity' => $severity,
                'title' => mb_substr($title, 0, 200),
                'message' => $message !== null ? mb_substr($message, 0, 500) : null,
                'source' => StatusIncident::SOURCE_MANUAL,
                'state' => $state,
                'created_by' => $this->author($author),
                'started_at' => now(),
            ]);

            $incident->updates()->create([
                'state' => $state,
                'message' => $message ?: $title,
                'author' => $this->author($author),
                'created_at' => now(),
            ]);

            return $incident;
        });

        $icon = $severity === ComponentStatus::MajorOutage ? '🔴' : '🟠';
        $this->alerter->send(trim("{$icon} {$this->name($incident)}: {$incident->title} ({$state->label('en')}). ".($message ?? '')));
        $this->fire($incident, IncidentUpdated::POSTED, $incident->updates()->first());

        return $incident;
    }

    /**
     * Adds a timeline entry. "resolved" closes the incident; only incidents posted by hand can
     * be resolved this way (automatic ones resolve when the checks recover).
     */
    public function update(StatusIncident $incident, IncidentState $state, string $message, ?string $author = null): StatusIncidentUpdate
    {
        if ($incident->resolved_at !== null) {
            throw new InvalidArgumentException('This incident is already resolved.');
        }

        if ($state === IncidentState::Resolved && ! $incident->isManual()) {
            throw new InvalidArgumentException('Automatic incidents resolve when the checks recover.');
        }

        $update = DB::transaction(function () use ($incident, $state, $message, $author) {
            $update = $incident->updates()->create([
                'state' => $state,
                'message' => $message,
                'author' => $this->author($author),
                'created_at' => now(),
            ]);

            $incident->update([
                'state' => $state,
                'resolved_at' => $state === IncidentState::Resolved ? now() : null,
            ]);

            return $update;
        });

        if ($state === IncidentState::Resolved) {
            $duration = StatusDuration::format((int) $incident->started_at->diffInMinutes(now()));
            $this->alerter->send("✅ {$this->name($incident)}: ".($incident->title ?: 'incident')." resolved after {$duration}. {$message}");
        } else {
            $this->alerter->send("📝 {$this->name($incident)} — {$state->label('en')}: {$message}");
        }

        $this->fire($incident, IncidentUpdated::UPDATED, $update);

        return $update;
    }

    public function postmortem(StatusIncident $incident, ?string $text): StatusIncident
    {
        if ($incident->resolved_at === null) {
            throw new InvalidArgumentException('Write the cause and fix once the incident is resolved.');
        }

        $incident->update(['postmortem' => filled($text) ? $text : null]);
        $this->fire($incident, IncidentUpdated::POSTMORTEM);

        return $incident;
    }

    private function name(StatusIncident $incident): string
    {
        return $this->registry->label($incident->component, 'en');
    }

    private function author(?string $author): ?string
    {
        return $author !== null ? mb_substr($author, 0, 191) : null;
    }

    private function fire(StatusIncident $incident, string $action, ?StatusIncidentUpdate $update = null): void
    {
        try {
            event(new IncidentUpdated($incident, $action, $update));
        } catch (Throwable $e) {
            Log::warning('An IncidentUpdated listener failed.', ['exception' => $e::class]);
        }

        $this->alerter->event('incident.'.$action, [
            'id' => $incident->id,
            'component' => $incident->component,
            'label' => $this->name($incident),
            'severity' => $incident->severity->value,
            'title' => $incident->title,
            'state' => $incident->state?->value,
            'source' => $incident->source,
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'update' => $update ? ['state' => $update->state->value, 'message' => $update->message, 'author' => $update->author] : null,
            'postmortem' => $incident->postmortem,
        ]);
    }
}
