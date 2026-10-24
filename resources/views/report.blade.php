{{--
    Monthly uptime report, in the same look as the status page: the standalone layout (brand
    bar, back button), the page stylesheet (--sst-* tokens, so extra_css branding applies), the
    hero, KPI cards, panels and pills. The PDF (dompdf) uses layouts.report-pdf, which gives the
    same classes simple styles. $report comes from GetStatusReportAction::execute(); $links
    (web only) holds the navigation URLs.
--}}
@extends(($pdf ?? false) ? 'system-status::layouts.report-pdf' : 'system-status::layouts.standalone')

@php
    use Technobase\SystemStatus\Support\StatusDuration;

    $pdf = $pdf ?? false;
    $links = $links ?? null;
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 3), '0'), '.').'%';
    $tone = function (?float $uptime, ?float $target) {
        if ($uptime === null) {
            return 'muted';
        }
        if ($target !== null) {
            return $uptime >= $target ? 'ok' : ($uptime >= $target - 0.5 ? 'warn' : 'bad');
        }

        return $uptime >= 99.9 ? 'ok' : ($uptime >= 99 ? 'warn' : 'bad');
    };
    // The bar shows 95 % … 100 %, where the differences that matter are.
    $barWidth = fn (?float $value) => $value === null ? 0 : max(2, min(100, ($value - 95) / 5 * 100));
    $duration = fn (int $minutes) => $minutes > 0 ? StatusDuration::format($minutes) : '—';
    $averageTone = $tone($report['average_uptime'], null);
@endphp

@section('title')
    @lang('system-status::status.status_report_title') · {{ $report['month_label'] }}
@endsection

@push($pdf ? 'report-styles' : config('system-status.stacks.styles', 'styles'))
    @unless($pdf)
        <style>{!! $styles ?? '' !!}</style>
        @if(filled($extraStyles ?? null))
            <style>{!! $extraStyles !!}</style>
        @endif
    @endunless
    <style>
        /* Report pieces the page stylesheet does not have. Colours have plain fallbacks for dompdf. */
        .sst-report .tone-ok { color: #15803d; } .sst-report .tone-warn { color: #b45309; }
        .sst-report .tone-bad { color: #b91c1c; } .sst-report .tone-muted { color: #6b7280; }
        .sst-report .sst-kpi-sub { font-size: .72rem; color: #6b7280; }
        .sst-report .sst-kpi[data-tone="ok"] { --sst-tone: var(--sst-ok); }
        .sst-report .sst-kpi[data-tone="warn"] { --sst-tone: var(--sst-degraded); }
        .sst-report .sst-kpi[data-tone="bad"] { --sst-tone: var(--sst-major); }
        .sst-report .sst-kpi[data-tone="muted"] { --sst-tone: var(--sst-muted); }
        .sst-report .sst-hero-actions .sst-month {
            padding: .4rem .8rem; border: 1px solid rgb(255 255 255 / .45); border-radius: 999px;
            background: rgb(255 255 255 / .14); color: #fff; font: inherit; font-size: .8125rem; font-weight: 600;
            color-scheme: dark; cursor: pointer;
        }
        .sst-report .sst-refresh[aria-disabled="true"] { opacity: .45; pointer-events: none; }
        .sst-report .sst-table { width: 100%; }
        .sst-report .sst-table th { padding: .55rem .75rem; text-align: start; }
        .sst-report .sst-table tr.sst-report-group td {
            padding-block-start: .9rem; background: #fcfcfd; background: var(--sst-surface-2, #fcfcfd);
            font-size: .7rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #6b7280;
        }
        .sst-report .sst-report-name { font-weight: 600; white-space: nowrap; }
        .sst-report .sst-strip { white-space: nowrap; font-size: 0; line-height: 0; }
        .sst-report .sst-strip i { display: inline-block; width: 5px; height: 18px; margin-inline-end: 1px; border-radius: 1.5px; background: #e5e7eb; }
        .sst-report .lvl-ok { background: #22c55e !important; } .sst-report .lvl-degraded { background: #f59e0b !important; }
        .sst-report .lvl-partial { background: #f97316 !important; } .sst-report .lvl-major { background: #dc2626 !important; }
        .sst-report .lvl-maintenance { background: #2563eb !important; } .sst-report .lvl-none { background: #e5e7eb !important; }
        .sst-report .lvl-future { background: #f3f4f6 !important; }
        .sst-report .sst-report-uptime { min-width: 120px; }
        .sst-report .sst-report-pct { font-weight: 800; font-variant-numeric: tabular-nums; }
        .sst-report .sst-report-bar { position: relative; height: 6px; margin-top: 5px; border-radius: 999px; background: #eef0f4; }
        .sst-report .sst-report-fill { position: absolute; top: 0; inset-inline-start: 0; left: 0; height: 6px; border-radius: 999px; }
        [dir="rtl"] .sst-report .sst-report-fill { left: auto; }
        .sst-report .sst-report-fill.tone-ok { background: #22c55e; } .sst-report .sst-report-fill.tone-warn { background: #f59e0b; }
        .sst-report .sst-report-fill.tone-bad { background: #dc2626; } .sst-report .sst-report-fill.tone-muted { background: #d1d5db; }
        .sst-report .sst-report-target { position: absolute; top: -3px; width: 2px; height: 12px; background: #111827; opacity: .55; }
        .sst-report .sst-num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .sst-report .sst-incident .sst-postmortem { white-space: pre-line; }
        [dir="rtl"] .sst-report .fa-chevron-left,
        [dir="rtl"] .sst-report .fa-chevron-right { transform: scaleX(-1); }
        .sst-report .sst-report-footnote { margin: .5rem 0 0; font-size: .78rem; color: #6b7280; }
        @media (max-width: 575.98px) {
            .sst-report .sst-hero-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
            .sst-report .sst-hero-actions .sst-month { flex: 1 1 calc(100% - 6.5rem); min-width: 0; text-align: center; }
            .sst-report .sst-hero-actions .sst-refresh { flex: 0 0 auto; justify-content: center; }
            .sst-report .sst-hero-actions .sst-refresh:has(span) { flex: 1 1 30%; }
        }
        @media print {
            .sst-bar, .sst-report .sst-hero-actions { display: none !important; }
            .sst-report .sst-hero, .sst-report .sst-strip i, .sst-report .sst-report-fill, .sst-report .sst-pill, .sst-report .sst-swatch {
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            .sst-standalone-main { padding: 0; }
            body.sst-standalone { background: #fff; }
        }
    </style>
@endpush

@section('content')
    <div class="content sst sst-report">
        {{-- Hero: brand colour, month and the report actions --}}
        <section class="sst-hero" style="--sst-tone: var(--sst-accent);">
            <div class="sst-hero-main">
                <span class="sst-hero-icon" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
                <div class="sst-hero-text">
                    <span class="sst-hero-eyebrow">{{ $report['app'] }} · @lang('system-status::status.status_report_title')</span>
                    <h2 class="sst-hero-title">{{ $report['month_label'] }}</h2>
                    <p class="sst-hero-meta">@lang('system-status::status.status_report_generated', ['time' => $report['generated_at']])</p>
                </div>
            </div>
            @if($links)
                <div class="sst-hero-actions">
                    <a class="sst-refresh" href="{{ $links['previous'] }}" aria-label="{{ __('system-status::status.status_report_previous') }}">
                        <i class="fas fa-chevron-left" aria-hidden="true"></i>
                    </a>
                    <input class="sst-month" type="month" id="sstMonth" value="{{ $report['month'] }}" max="{{ now()->format('Y-m') }}"
                           aria-label="{{ __('system-status::status.status_report_month') }}" data-url="{{ $links['template'] }}">
                    <a class="sst-refresh" href="{{ $links['next'] ?? '#' }}" aria-disabled="{{ $links['next'] ? 'false' : 'true' }}"
                       aria-label="{{ __('system-status::status.status_report_next') }}">
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </a>
                    <a class="sst-refresh" href="{{ $links['csv'] }}"><i class="fas fa-file-csv" aria-hidden="true"></i><span>CSV</span></a>
                    @if($links['pdf'])
                        <a class="sst-refresh" href="{{ $links['pdf'] }}"><i class="fas fa-file-pdf" aria-hidden="true"></i><span>PDF</span></a>
                    @endif
                    <button type="button" class="sst-refresh" id="sstPrint"><i class="fas fa-print" aria-hidden="true"></i><span>@lang('system-status::status.status_report_print')</span></button>
                </div>
            @endif
        </section>

        {{-- Key numbers, like the page's KPI cards --}}
        <div class="sst-kpis">
            <div class="sst-kpi" data-tone="{{ $averageTone }}">
                <span class="sst-kpi-label">@lang('system-status::status.status_report_average')</span>
                <strong class="sst-kpi-value tone-{{ $averageTone }}">{{ $percent($report['average_uptime']) }}</strong>
                <span class="sst-kpi-sub">@lang('system-status::status.status_report_components_count', ['count' => count($report['components'])])</span>
            </div>
            @php($slaTone = $report['missed'] ? 'bad' : ($report['with_target'] ? 'ok' : 'muted'))
            <div class="sst-kpi" data-tone="{{ $slaTone }}">
                <span class="sst-kpi-label">@lang('system-status::status.status_report_sla')</span>
                <strong class="sst-kpi-value tone-{{ $slaTone }}">{{ $report['with_target'] ? ($report['with_target'] - $report['missed']).' / '.$report['with_target'] : '—' }}</strong>
                <span class="sst-kpi-sub">{{ $report['with_target'] ? __('system-status::status.status_report_sla_met') : __('system-status::status.status_report_no_targets') }}</span>
            </div>
            @php($incidentTone = $report['incident_count'] ? 'warn' : 'ok')
            <div class="sst-kpi" data-tone="{{ $incidentTone }}">
                <span class="sst-kpi-label">@lang('system-status::status.status_incidents')</span>
                <strong class="sst-kpi-value tone-{{ $incidentTone }}">{{ number_format($report['incident_count']) }}</strong>
                <span class="sst-kpi-sub">
                    @if(($report['deploy_count'] ?? 0) > 0)
                        @lang('system-status::status.status_report_deploys', ['count' => $report['deploy_count'], 'after' => $report['incidents_after_deploy']])
                    @else
                        @lang('system-status::status.status_report_in_month')
                    @endif
                </span>
            </div>
            @php($downTone = $report['outage_minutes'] ? 'bad' : 'ok')
            <div class="sst-kpi" data-tone="{{ $downTone }}">
                <span class="sst-kpi-label">@lang('system-status::status.status_report_downtime')</span>
                <strong class="sst-kpi-value tone-{{ $downTone }}">{{ $duration($report['outage_minutes']) }}</strong>
                <span class="sst-kpi-sub">@lang('system-status::status.status_report_maintenance_sub', ['duration' => $duration($report['maintenance_minutes'])])</span>
            </div>
        </div>

        {{-- Components --}}
        <section class="sst-panel">
            <header class="sst-panel-head">
                <h3 class="sst-panel-title">@lang('system-status::status.status_report_components')</h3>
                <ul class="sst-legend">
                    <li><span class="sst-swatch lvl-ok"></span>@lang('system-status::status.status_operational')</li>
                    <li><span class="sst-swatch lvl-degraded"></span>@lang('system-status::status.status_degraded')</li>
                    <li><span class="sst-swatch lvl-partial"></span>@lang('system-status::status.status_overall_partial')</li>
                    <li><span class="sst-swatch lvl-major"></span>@lang('system-status::status.status_major_outage')</li>
                    <li><span class="sst-swatch lvl-maintenance"></span>@lang('system-status::status.status_maintenance')</li>
                    <li><span class="sst-swatch lvl-none"></span>@lang('system-status::status.status_no_data')</li>
                </ul>
            </header>
            <div style="overflow-x: auto;">
                <table class="sst-table">
                    <thead>
                    <tr>
                        <th>@lang('system-status::status.status_form_component')</th>
                        <th>@lang('system-status::status.status_report_day_by_day')</th>
                        <th>@lang('system-status::status.status_report_uptime')</th>
                        <th>@lang('system-status::status.status_report_sla')</th>
                        <th class="sst-num">@lang('system-status::status.status_report_downtime')</th>
                        <th class="sst-num">@lang('system-status::status.status_maintenance')</th>
                        <th class="sst-num">@lang('system-status::status.status_incidents')</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach(collect($report['components'])->groupBy('group') as $components)
                        <tr class="sst-report-group"><td colspan="7">{{ $components->first()['group_label'] }}</td></tr>
                        @foreach($components as $c)
                            @php($cTone = $tone($c['uptime'], $c['target']))
                            <tr>
                                <td class="sst-report-name">{{ $c['label'] }}</td>
                                <td class="sst-strip">@foreach($c['days'] as $day)<i class="lvl-{{ $day['level'] }}" title="{{ $report['month'] }}-{{ str_pad($day['day'], 2, '0', STR_PAD_LEFT) }}"></i>@endforeach</td>
                                <td class="sst-report-uptime">
                                    <span class="sst-report-pct tone-{{ $cTone }}">{{ $percent($c['uptime']) }}</span>
                                    <div class="sst-report-bar">
                                        <span class="sst-report-fill tone-{{ $cTone }}" style="width: {{ $barWidth($c['uptime']) }}%"></span>
                                        @if($c['target'] !== null)
                                            <span class="sst-report-target" style="inset-inline-start: {{ $barWidth($c['target']) }}%; left: {{ $barWidth($c['target']) }}%"
                                                  title="{{ __('system-status::status.status_sla_target', ['target' => $c['target']]) }}"></span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if($c['met'] === true)
                                        <span class="sst-pill" data-status="operational">✓ {{ $percent($c['target']) }}</span>
                                    @elseif($c['met'] === false)
                                        <span class="sst-pill" data-status="major_outage">✗ {{ $percent($c['target']) }}</span>
                                    @else
                                        <span class="sst-pill" data-status="not_configured">{{ $c['target'] === null ? '—' : $percent($c['target']) }}</span>
                                    @endif
                                </td>
                                <td class="sst-num {{ $c['outage_minutes'] ? 'tone-bad' : '' }}">{{ $duration($c['outage_minutes']) }}</td>
                                <td class="sst-num">{{ $duration($c['maintenance_minutes']) }}</td>
                                <td class="sst-num">{{ $c['incidents'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Incidents of the month, like the page's incident list --}}
        <section class="sst-panel">
            <header class="sst-panel-head">
                <h3 class="sst-panel-title">@lang('system-status::status.status_report_incidents_title')</h3>
            </header>
            <div class="sst-panel-body">
                @forelse($report['incidents'] as $incident)
                    <article class="sst-incident" data-status="{{ $incident['severity'] }}" data-ongoing="{{ $incident['resolved_at'] ? '0' : '1' }}">
                        <span class="sst-incident-marker" aria-hidden="true"></span>
                        <div class="sst-incident-body">
                            <div class="sst-incident-head">
                                <strong class="sst-incident-component">{{ $incident['component'] }}</strong>
                                <span class="sst-pill" data-status="{{ $incident['severity'] }}">{{ $incident['severity_label'] }}</span>
                                <span class="sst-incident-state">{{ $incident['resolved_at'] ? __('system-status::status.status_resolved') : __('system-status::status.status_ongoing') }}</span>
                            </div>
                            @if($incident['title'])
                                <p class="sst-incident-title">{{ $incident['title'] }}</p>
                            @endif
                            <small class="sst-incident-time">
                                {{ $incident['started_at'] }} → {{ $incident['resolved_at'] ?? '…' }} · {{ StatusDuration::format($incident['duration_minutes']) }}
                                @if(! empty($incident['after_deploy']))
                                    · @lang('system-status::status.status_report_after_deploy', ['version' => $incident['after_deploy']])
                                @endif
                                @if($incident['affected_by'])
                                    · @lang('system-status::status.status_report_affected_by', ['component' => $incident['affected_by']])
                                @endif
                            </small>
                            @if($incident['postmortem'])
                                <div class="sst-postmortem">
                                    <strong>@lang('system-status::status.status_postmortem')</strong>
                                    <p>{{ $incident['postmortem'] }}</p>
                                </div>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="sst-empty-state"><i class="fas fa-check-circle" aria-hidden="true"></i><span>@lang('system-status::status.status_report_no_incidents')</span></p>
                @endforelse
            </div>
        </section>

        <p class="sst-report-footnote">@lang('system-status::status.status_report_footnote')</p>
    </div>
@endsection

@if($links)
    @push(config('system-status.stacks.scripts', 'scripts'))
        <script>
            (function () {
                var input = document.getElementById('sstMonth');
                input.addEventListener('change', function () {
                    if (/^\d{4}-\d{2}$/.test(input.value)) {
                        window.location.href = input.dataset.url.replace('0000-00', input.value);
                    }
                });
                document.getElementById('sstPrint').addEventListener('click', function () {
                    window.print();
                });
            })();
        </script>
    @endpush
@endif
