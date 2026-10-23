@php
    $tones = [
        'operational' => '#16a34a', 'degraded' => '#f59e0b', 'partial' => '#f97316',
        'major' => '#dc2626', 'major_outage' => '#dc2626', 'maintenance' => '#2563eb',
    ];
    $level = $stale ? 'major' : $overall['level'];
@endphp
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.30s="">
    <x-pulse::card-header
        name="{{ __('system-status::status.system_status') }}"
        details="{{ $lastRunAgo ? __('system-status::status.status_last_checked', ['time' => $lastRunAgo]) : '' }}"
    >
        <x-slot:icon>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l3-8 4 16 3-8h4"/>
            </svg>
        </x-slot:icon>
        <x-slot:actions>
            <a href="{{ $url }}" class="text-xs text-gray-500 dark:text-gray-400 underline">@lang('system-status::status.system_status')</a>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        <div style="display:flex;align-items:center;gap:.6rem;padding:.5rem 0 .75rem">
            <span style="width:.75rem;height:.75rem;border-radius:50%;background:{{ $tones[$level] ?? '#9ca3af' }}"></span>
            <strong class="text-gray-900 dark:text-gray-100">{{ $overall['label'] }}</strong>
            @if($openIncidents)
                <span class="text-xs text-gray-500 dark:text-gray-400">· @lang('system-status::status.status_kpi_open_incidents'): {{ $openIncidents }}</span>
            @endif
        </div>

        @forelse($problems as $component)
            <div style="display:flex;align-items:flex-start;gap:.5rem;padding:.4rem 0;border-top:1px solid rgb(127 127 127 / .15)">
                <span style="flex:none;margin-top:.35rem;width:.5rem;height:.5rem;border-radius:50%;background:{{ $tones[$component['status']] ?? '#9ca3af' }}"></span>
                <div style="min-width:0">
                    <div class="text-sm text-gray-900 dark:text-gray-100">{{ $component['label'] }} · {{ $component['status_label'] }}</div>
                    @if($component['message'])
                        <div class="text-xs text-gray-500 dark:text-gray-400" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $component['message'] }}</div>
                    @endif
                </div>
            </div>
        @empty
            <x-pulse::no-results />
        @endforelse
    </x-pulse::scroll>
</x-pulse::card>
