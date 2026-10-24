<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Events\ComponentStatusChanged;
use Technobase\SystemStatus\Events\MaintenanceEnded;
use Technobase\SystemStatus\Events\MaintenanceStarted;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Support\StatusAlerter;
use Technobase\SystemStatus\Support\StatusHeartbeat;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * One run of the System Status checks (`status:check`, every minute): flush traffic counters,
 * run the probes that are due (ProbeRunner), work out each component's status
 * (ComponentEvaluator), confirm changes after `confirm_runs` runs in a row, open or resolve
 * incidents (IncidentRecorder), alert and update the daily rollup (DailyRollup). All state is in
 * the database, so a Redis outage is still detected and stored. The outcome of each run is kept
 * in StatusHeartbeat, so a run that fails is visible too.
 */
class StatusService
{
    public function __construct(
        private readonly StatusMetrics $metrics,
        private readonly StatusRegistry $registry,
        private readonly StatusAlerter $alerter,
        private readonly DeployService $deploys,
        private readonly ProbeRunner $probes,
        private readonly ComponentEvaluator $evaluator,
        private readonly IncidentRecorder $incidents,
        private readonly DailyRollup $rollup,
    ) {}

    /**
     * This run's failing components that depend on another failing component ('depends_on'),
     * mapped to the root cause.
     *
     * @var array<string, string>
     */
    private array $affectedBy = [];

    /**
     * @return array{flushed_minutes: int, probes_run: int, changes: array<int, string>}
     */
    public function run(): array
    {
        $now = now();

        try {
            $result = $this->check($now);
        } catch (Throwable $e) {
            StatusHeartbeat::failed($e, $now);

            throw $e;
        }

        StatusHeartbeat::succeeded($now);

        return $result;
    }

    /**
     * Rows of the 90-day history, keyed by component.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function dailyHistory(int $days = 90): Collection
    {
        return $this->rollup->history($days);
    }

    /**
     * @return array{flushed_minutes: int, probes_run: int, changes: array<int, string>}
     */
    private function check(CarbonInterface $now): array
    {
        $this->alerter->begin();
        $this->incidents->recordSchedulerGap($now);

        $flushed = 0;
        try {
            $flushed = $this->metrics->flush();
        } catch (Throwable $e) {
            Log::warning('Status metrics flush failed.', ['exception' => $e::class]);
        }

        [$probeResults, $probesRun] = $this->probes->run($now);
        $this->probes->dispatchQueueHeartbeats($now);
        $maintenance = $this->maintenanceWindows($now);
        $traffic = $this->evaluator->traffic($now);
        $manual = $this->evaluator->openManualIncidents();

        $changes = [];
        $daily = [];
        $evaluated = [];

        foreach ($this->registry->components() as $component => $entry) {
            $evaluated[$component] = $this->evaluator->evaluate(
                $component,
                $entry['definition'],
                $probeResults[$component] ?? [],
                $traffic[$component] ?? [],
                $manual[$component] ?? null,
            );
        }

        $this->affectedBy = [];
        $applied = [];

        // Dependencies first, so a component sees its dependency's status after this run.
        foreach ($this->dependencyOrder(array_keys($evaluated)) as $component) {
            [$status, $message, $configured] = $evaluated[$component];

            if (! isset($maintenance[$component]) && ($root = $this->failingDependency($component, $status, $evaluated, $applied, $maintenance))) {
                $this->affectedBy[$component] = $root;
                $message = trim('Affected by '.$this->registry->label($root, 'en').($message ? ' — '.$message : ''));
            }

            $state = isset($maintenance[$component])
                ? $this->applyMaintenance($component, $maintenance[$component], $configured, $now)
                : $this->applyStatus($component, $status, $message, $configured, $now, $changes);
            $applied[$component] = $state->status;
            $daily[] = $this->rollup->row($component, $state->status, $now);
        }

        $this->rollup->add($daily);
        $this->incidents->sendReminders($now, $maintenance);
        $this->safely(fn () => $this->deploys->compareDue($now));
        $this->alerter->flush($this->affectedBy, $this->registry);
        $this->alerter->flappingEnded(function (string $component) {
            $status = StatusComponent::query()->where('component', $component)->value('status');

            return $this->registry->label($component, 'en').' is '.str_replace('_', ' ', $status instanceof ComponentStatus ? $status->value : (string) $status);
        });

        return ['flushed_minutes' => $flushed, 'probes_run' => $probesRun, 'changes' => $changes];
    }

    /**
     * Component keys with each one's dependencies ('depends_on') before it; cycles are broken.
     *
     * @param  array<int, string>  $components
     * @return array<int, string>
     */
    private function dependencyOrder(array $components): array
    {
        $definitions = $this->registry->components();
        $ordered = [];
        $visiting = [];

        $visit = function (string $component) use (&$visit, &$ordered, &$visiting, $definitions, $components) {
            if (isset($ordered[$component]) || isset($visiting[$component]) || ! in_array($component, $components, true)) {
                return;
            }

            $visiting[$component] = true;
            foreach ((array) ($definitions[$component]['definition']['depends_on'] ?? []) as $dependency) {
                $visit((string) $dependency);
            }
            unset($visiting[$component]);
            $ordered[$component] = true;
        };

        foreach ($components as $component) {
            $visit($component);
        }

        return array_keys($ordered);
    }

    /**
     * The root cause when a failing component depends on a component that is failing too (its
     * confirmed status after this run, or what this run observed); null otherwise.
     *
     * @param  array<string, array{0: ComponentStatus, 1: ?string, 2: bool}>  $evaluated
     * @param  array<string, ComponentStatus>  $applied
     * @param  array<string, StatusMaintenance>  $maintenance
     */
    private function failingDependency(string $component, ComponentStatus $observed, array $evaluated, array $applied, array $maintenance): ?string
    {
        if (! $observed->isProblem()) {
            return null;
        }

        $dependencies = (array) ($this->registry->components()[$component]['definition']['depends_on'] ?? []);

        foreach ($dependencies as $dependency) {
            if ($dependency === $component || ! isset($evaluated[$dependency]) || isset($maintenance[$dependency])) {
                continue;
            }

            $failing = $evaluated[$dependency][0]->isProblem() || ($applied[$dependency] ?? null)?->isProblem();
            if ($failing) {
                return $this->affectedBy[$dependency] ?? $dependency;
            }
        }

        return null;
    }

    /**
     * Confirms a status change once it has been seen `confirm_runs` runs in a row.
     */
    private function applyStatus(
        string $component,
        ComponentStatus $observed,
        ?string $message,
        bool $configured,
        CarbonInterface $now,
        array &$changes,
    ): StatusComponent {
        $state = StatusComponent::query()->firstOrNew(['component' => $component], [
            'status' => ComponentStatus::Operational,
            'candidate_runs' => 0,
        ]);

        $state->configured = $configured;
        $state->checked_at = $now;
        $wasAffectedBy = $state->affected_by;
        $state->affected_by = $this->affectedBy[$component] ?? null;

        // Leaving a maintenance window: back to operational; a problem found after the work
        // must be confirmed like any other change.
        if ($state->status === ComponentStatus::Maintenance) {
            $state->status = ComponentStatus::Operational;
            $state->changed_at = $now;
            $state->candidate_status = null;
            $state->candidate_runs = 0;

            if ($observed === ComponentStatus::Operational) {
                // An incident left open from before the window is over now.
                $this->incidents->record($component, ComponentStatus::Operational, $observed, $message, $now, $this->affectedBy[$component] ?? null);
            }
        }

        if ($observed === $state->status) {
            $state->candidate_status = null;
            $state->candidate_runs = 0;
            $state->message = $message;
            $state->save();

            // The dependency recovered but this component did not: now it is its own problem.
            if ($wasAffectedBy && ! $state->affected_by && $observed->isProblem()) {
                StatusIncident::query()->open()->where('component', $component)->update(['affected_by' => null]);
                $name = $this->registry->label($component, 'en');
                $this->alerter->change($component, trim("🟠 {$name}: still ".IncidentRecorder::statusWords($observed).' after '.$this->registry->label($wasAffectedBy, 'en').' recovered. '.($message ?? '')), $name);
            }

            return $state;
        }

        $previous = null;

        if ($state->candidate_status === $observed) {
            $state->candidate_runs++;
        } else {
            $state->candidate_status = $observed;
            $state->candidate_runs = 1;
        }

        if ($state->candidate_runs >= max(1, (int) $this->registry->threshold($component, 'confirm_runs', 2))) {
            $previous = $state->status;
            $state->status = $observed;
            $state->message = $message;
            $state->changed_at = $now;
            $state->candidate_status = null;
            $state->candidate_runs = 0;

            $changes[] = $component.': '.$previous->value.' -> '.$observed->value;
            $this->incidents->record($component, $previous, $observed, $message, $now, $this->affectedBy[$component] ?? null);
        }

        $state->save();

        if ($previous !== null) {
            $this->dispatchChange($component, $previous, $observed, $message, $now);
        }

        return $state;
    }

    /**
     * A component inside a maintenance window shows Maintenance straight away: no confirmation,
     * no incident, no alert and no ComponentStatusChanged event.
     */
    private function applyMaintenance(string $component, StatusMaintenance $maintenance, bool $configured, CarbonInterface $now): StatusComponent
    {
        $state = StatusComponent::query()->firstOrNew(['component' => $component], [
            'status' => ComponentStatus::Operational,
            'candidate_runs' => 0,
        ]);

        if ($state->status !== ComponentStatus::Maintenance) {
            $state->changed_at = $now;
        }

        $state->status = ComponentStatus::Maintenance;
        $state->message = $maintenance->title;
        $state->configured = $configured;
        $state->candidate_status = null;
        $state->candidate_runs = 0;
        $state->checked_at = $now;
        $state->save();

        return $state;
    }

    /**
     * Active maintenance windows keyed by component. Fires MaintenanceStarted for windows that
     * just began and MaintenanceEnded for windows that are over.
     *
     * @return array<string, StatusMaintenance>
     */
    private function maintenanceWindows(CarbonInterface $now): array
    {
        try {
            StatusMaintenance::query()->active($now)->whereNull('announced_at')->get()
                ->each(function (StatusMaintenance $maintenance) use ($now) {
                    $maintenance->update(['announced_at' => $now]);
                    $this->safely(fn () => event(new MaintenanceStarted($maintenance)));
                    $this->alerter->event('maintenance.started', $this->maintenancePayload($maintenance));
                });

            StatusMaintenance::query()->where('ends_at', '<=', $now)->whereNull('ended_at')->get()
                ->each(function (StatusMaintenance $maintenance) use ($now) {
                    $maintenance->update(['ended_at' => $now, 'announced_at' => $maintenance->announced_at ?? $maintenance->starts_at]);
                    $this->safely(fn () => event(new MaintenanceEnded($maintenance)));
                    $this->alerter->event('maintenance.ended', $this->maintenancePayload($maintenance));
                });

            return StatusMaintenance::activeByComponent($now);
        } catch (Throwable $e) {
            Log::warning('Status maintenance windows could not be read.', ['exception' => $e::class]);

            return [];
        }
    }

    private function maintenancePayload(StatusMaintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id,
            'title' => $maintenance->title,
            'components' => array_values((array) $maintenance->components),
            'starts_at' => $maintenance->starts_at->toIso8601String(),
            'ends_at' => $maintenance->ends_at->toIso8601String(),
        ];
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('A System Status listener failed.', ['exception' => $e::class]);
        }
    }

    /**
     * Lets the application react to a confirmed change (ComponentStatusChanged). A failing
     * listener never stops the status run.
     */
    private function dispatchChange(
        string $component,
        ComponentStatus $previous,
        ComponentStatus $current,
        ?string $message,
        CarbonInterface $now,
    ): void {
        try {
            event(new ComponentStatusChanged($component, $this->registry->label($component), $previous, $current, $message, $now));
        } catch (Throwable $e) {
            Log::warning('A ComponentStatusChanged listener failed.', ['exception' => $e::class]);
        }

        $this->alerter->event('component.status_changed', [
            'component' => $component,
            'label' => $this->registry->label($component, 'en'),
            'previous' => $previous->value,
            'current' => $current->value,
            'message' => $message,
            'changed_at' => $now->toIso8601String(),
        ]);
    }
}
