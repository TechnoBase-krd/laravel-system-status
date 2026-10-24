<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusDeploy;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Support\StatusAlerter;
use Technobase\SystemStatus\Support\StatusDuration;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * Automatic incidents of a status:check run: opens, upgrades and resolves them on a confirmed
 * change, sends "still down" reminders and records a scheduler gap. Alerts go through
 * StatusAlerter.
 */
class IncidentRecorder
{
    public function __construct(
        private readonly StatusRegistry $registry,
        private readonly StatusAlerter $alerter,
        private readonly DailyRollup $rollup,
    ) {}

    /**
     * A confirmed change from $previous to $current. $affectedBy is the failing dependency
     * this component's problem comes from, if any.
     */
    public function record(
        string $component,
        ComponentStatus $previous,
        ComponentStatus $current,
        ?string $message,
        CarbonInterface $now,
        ?string $affectedBy = null,
    ): void {
        $open = StatusIncident::query()->open()->automatic()->where('component', $component)->latest('started_at')->first();
        $manual = StatusIncident::query()->open()->manual()->where('component', $component)->get()
            ->max(fn (StatusIncident $incident) => $incident->severity->severity());
        $name = $this->registry->label($component, 'en');

        if ($current === ComponentStatus::Operational) {
            if ($open) {
                $open->update(['resolved_at' => $now]);
                $duration = StatusDuration::format((int) $open->started_at->diffInMinutes($now));
                $text = "✅ {$name} recovered after {$duration}.";

                // Its outage was reported as part of the dependency's: no separate recovery alert.
                $open->affected_by ? Log::info('[system-status] '.$text) : $this->alerter->change($component, $text, $name, true);
            }

            return;
        }

        // People already know about it: a manual incident at this severity or worse is open.
        if (! $open && $manual !== null && $current->severity() <= $manual) {
            return;
        }

        if ($open) {
            if ($current->severity() > $open->severity->severity()) {
                $open->update(['severity' => $current, 'message' => $message]);
            }
        } else {
            StatusIncident::query()->create([
                'component' => $component,
                'severity' => $current,
                'message' => $message,
                'started_at' => $now,
                'affected_by' => $affectedBy,
            ]);
        }

        $icon = $current === ComponentStatus::MajorOutage ? '🔴' : '🟠';
        $label = self::statusWords($current);
        $this->alerter->change($component, trim("{$icon} {$name}: {$label}. ".($message ?? '').($open ? '' : $this->afterDeploy($now))), $name);
    }

    /**
     * "Still down" reminders for major outages open longer than alerts.reminder_minutes, repeated
     * every reminder_minutes until resolved.
     *
     * @param  array<string, StatusMaintenance>  $maintenance
     */
    public function sendReminders(CarbonInterface $now, array $maintenance): void
    {
        $minutes = (int) config('system-status.alerts.reminder_minutes');
        if ($minutes <= 0) {
            return;
        }

        $due = $now->copy()->subMinutes($minutes);

        try {
            StatusIncident::query()->open()
                ->where('severity', ComponentStatus::MajorOutage->value)
                ->where('started_at', '<=', $due)
                ->where(fn ($query) => $query->whereNull('reminded_at')->orWhere('reminded_at', '<=', $due))
                ->get()
                ->each(function (StatusIncident $incident) use ($now, $maintenance) {
                    $incident->update(['reminded_at' => $now]);

                    if (isset($maintenance[$incident->component])) {
                        return;
                    }

                    $duration = StatusDuration::format((int) $incident->started_at->diffInMinutes($now));
                    $what = $incident->title ?: $incident->message;
                    $this->alerter->send(trim("🔴 Still down: {$this->registry->label($incident->component, 'en')} for {$duration}. ".($what ?? '')));
                });
        } catch (Throwable $e) {
            Log::warning('Status reminders could not be sent.', ['exception' => $e::class]);
        }
    }

    /**
     * The scheduler cannot report its own outage, so the first run after a gap records it.
     */
    public function recordSchedulerGap(CarbonInterface $now): void
    {
        $lastRun = StatusComponent::query()->max('checked_at');
        if (! $lastRun) {
            return;
        }

        $lastRun = Carbon::parse($lastRun);
        $gapMinutes = (int) $lastRun->diffInMinutes($now);

        if ($gapMinutes <= (int) config('system-status.thresholds.heartbeat_stale_minutes', 3)) {
            return;
        }

        StatusIncident::query()->create([
            'component' => 'scheduler',
            'severity' => ComponentStatus::MajorOutage,
            'message' => "No status checks ran for {$gapMinutes} min (scheduler stopped or stuck).",
            'started_at' => $lastRun,
            'resolved_at' => $now,
        ]);

        $this->rollup->add([[
            'component' => 'scheduler',
            'day' => $now->toDateString(),
            'runs' => $gapMinutes,
            'degraded_runs' => 0,
            'outage_runs' => $gapMinutes,
            'maintenance_runs' => 0,
        ]]);

        $this->alerter->send("⚠️ Scheduler: no status checks ran from {$lastRun->format('Y-m-d H:i')} to {$now->format('H:i')} ({$gapMinutes} min). Running again now.");
    }

    public static function statusWords(ComponentStatus $status): string
    {
        return $status === ComponentStatus::MajorOutage ? 'major outage' : str_replace('_', ' ', $status->value);
    }

    /**
     * " (3 min after deploy 1.8.2)" for an incident that starts soon after a deploy.
     */
    private function afterDeploy(CarbonInterface $now): string
    {
        try {
            $deploy = StatusDeploy::justBefore($now);
        } catch (Throwable) {
            return '';
        }

        return $deploy ? ' ('.(int) $deploy->deployed_at->diffInMinutes($now).' min after deploy '.$deploy->label().')' : '';
    }
}
