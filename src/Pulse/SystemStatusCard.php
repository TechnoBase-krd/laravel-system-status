<?php

namespace Technobase\SystemStatus\Pulse;

use Illuminate\Contracts\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;

/**
 * A Laravel Pulse card with the overall status and the components that have a problem.
 * Registered only when laravel/pulse is installed. Add it to resources/views/vendor/pulse/dashboard.blade.php:
 *
 *     <livewire:system-status.pulse cols="4" />
 */
#[Lazy]
class SystemStatusCard extends Card
{
    public function render(GetStatusSummaryAction $summary): View
    {
        $data = $summary->execute('90d');

        $problems = collect($data['groups'])
            ->flatMap(fn (array $group) => $group['components'])
            ->filter(fn (array $component) => $component['configured'] && $component['status'] !== 'operational')
            ->values();

        return view('system-status::pulse.card', [
            'overall' => $data['overall'],
            'stale' => $data['stale'],
            'lastRunAgo' => $data['last_run_ago'],
            'problems' => $problems,
            'openIncidents' => collect($data['incidents'])->where('ongoing', true)->count(),
            'url' => route('system_status.index'),
        ]);
    }
}
