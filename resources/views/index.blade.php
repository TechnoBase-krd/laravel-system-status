@extends($layout ?? config('system-status.layout', 'layouts.app'))

@push(config('system-status.stacks.styles', 'styles'))
    @include('system-status::partials.tailwind')
    <style>{!! $styles !!}</style>
    @if(filled($extraStyles ?? null))
        <style>{!! $extraStyles !!}</style>
    @endif
@endpush

@section('title')
    @lang('system-status::status.system_status')
@endsection

@php
    $canManage = $canManage ?? false;
@endphp

@section('content')
    <div class="content sst" id="sst"
         data-summary-url="{{ route('system_status.summary') }}"
         data-component-url="{{ route('system_status.component', ['component' => '__component__']) }}"
         data-can-manage="{{ $canManage ? '1' : '0' }}"
         @if($canManage)
         data-csrf="{{ csrf_token() }}"
         data-maintenance-url="{{ route('system_status.maintenances.store') }}"
         data-maintenance-end-url="{{ route('system_status.maintenances.end', ['maintenance' => '__id__']) }}"
         data-incident-url="{{ route('system_status.incidents.store') }}"
         data-incident-update-url="{{ route('system_status.incidents.updates.store', ['incident' => '__id__']) }}"
         data-incident-edit-url="{{ route('system_status.incidents.update', ['incident' => '__id__']) }}"
         @endif
         >

        {{-- Overall status --}}
        <section class="sst-hero" id="sstHero" data-level="loading" aria-live="polite">
            <div class="sst-hero-main">
                <span class="sst-hero-icon" aria-hidden="true"><i id="sstHeroIcon" class="fas fa-circle-notch"></i></span>
                <div class="sst-hero-text">
                    <span class="sst-hero-eyebrow">@lang('system-status::status.system_status')</span>
                    <h2 class="sst-hero-title" id="sstHeroTitle">@lang('system-status::status.status_loading')</h2>
                    <p class="sst-hero-meta" id="sstHeroMeta">@lang('system-status::status.system_status_description', ['app' => $appName ?? config('app.name')])</p>
                </div>
            </div>
            <div class="sst-hero-actions">
                @if($canManage)
                    <button type="button" class="sst-refresh" data-open-panel="incident">
                        <i class="fas fa-bullhorn" aria-hidden="true"></i>
                        <span>@lang('system-status::status.status_report_incident')</span>
                    </button>
                    <button type="button" class="sst-refresh" data-open-panel="maintenance">
                        <i class="fas fa-tools" aria-hidden="true"></i>
                        <span>@lang('system-status::status.status_schedule_maintenance')</span>
                    </button>
                @endif
                <a class="sst-refresh" href="{{ route('system_status.report', ['month' => now()->format('Y-m'), 'format' => 'html']) }}">
                    <i class="fas fa-file-alt" aria-hidden="true"></i>
                    <span>@lang('system-status::status.status_monthly_report')</span>
                </a>
                <span class="sst-countdown" id="sstCountdown"></span>
                <button type="button" class="sst-refresh" id="sstRefresh">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                    <span>@lang('system-status::status.status_refresh')</span>
                </button>
            </div>
        </section>

        {{-- Key numbers --}}
        <div class="sst-kpis">
            <div class="sst-kpi">
                <span class="sst-kpi-label" id="sstKpiUptimeLabel">@lang('system-status::status.status_kpi_uptime')</span>
                <strong class="sst-kpi-value" id="sstKpiUptime">—</strong>
            </div>
            <div class="sst-kpi" data-tone="danger">
                <span class="sst-kpi-label">@lang('system-status::status.status_kpi_open_incidents')</span>
                <strong class="sst-kpi-value" id="sstKpiIncidents">—</strong>
            </div>
            <div class="sst-kpi" data-tone="warning">
                <span class="sst-kpi-label">@lang('system-status::status.status_kpi_affected')</span>
                <strong class="sst-kpi-value" id="sstKpiAffected">—</strong>
            </div>
            <div class="sst-kpi">
                <span class="sst-kpi-label">@lang('system-status::status.status_kpi_components')</span>
                <strong class="sst-kpi-value" id="sstKpiComponents">—</strong>
            </div>
        </div>

        {{-- Filters and legend --}}
        <div class="sst-toolbar">
            <div class="sst-segmented sst-range" role="group" aria-label="{{ __('system-status::status.status_range') }}">
                <button type="button" class="sst-segment" data-range="1h" aria-pressed="false">
                    <span class="sst-live-dot" aria-hidden="true"></span>@lang('system-status::status.status_range_1h')
                </button>
                <button type="button" class="sst-segment" data-range="24h" aria-pressed="false">@lang('system-status::status.status_range_24h')</button>
                <button type="button" class="sst-segment" data-range="7d" aria-pressed="false">@lang('system-status::status.status_range_7d')</button>
                <button type="button" class="sst-segment" data-range="90d" aria-pressed="true">@lang('system-status::status.status_range_90d')</button>
            </div>
            <div class="sst-segmented" role="group">
                <button type="button" class="sst-segment" data-filter="all" aria-pressed="true">@lang('system-status::status.status_filter_all')</button>
                <button type="button" class="sst-segment" data-filter="problems" aria-pressed="false">
                    @lang('system-status::status.status_filter_problems')
                    <span class="sst-segment-count" id="sstProblemCount">0</span>
                </button>
            </div>
            <label class="sst-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="sstSearch" placeholder="{{ __('system-status::status.status_search_placeholder') }}"
                       aria-label="{{ __('system-status::status.status_search_placeholder') }}">
            </label>
            <ul class="sst-legend">
                <li><span class="sst-swatch" data-level="ok"></span>@lang('system-status::status.status_operational')</li>
                <li><span class="sst-swatch" data-level="degraded"></span>@lang('system-status::status.status_degraded')</li>
                <li><span class="sst-swatch" data-level="partial"></span>@lang('system-status::status.status_overall_partial')</li>
                <li><span class="sst-swatch" data-level="major"></span>@lang('system-status::status.status_major_outage')</li>
                <li><span class="sst-swatch" data-level="maintenance"></span>@lang('system-status::status.status_maintenance')</li>
                <li><span class="sst-swatch" data-level="none"></span>@lang('system-status::status.status_no_data')</li>
            </ul>
        </div>

        {{-- Active and upcoming maintenance --}}
        <section class="sst-panel sst-maintenance-panel tw:mb-6" id="sstMaintenancePanel" hidden>
            <header class="sst-panel-head">
                <h3 class="sst-panel-title"><i class="fas fa-tools" aria-hidden="true"></i> @lang('system-status::status.status_scheduled_maintenance')</h3>
            </header>
            <div class="sst-panel-body" id="sstMaintenances"></div>
        </section>

        <div id="sstGroups" class="sst-groups">
            @for ($i = 0; $i < 3; $i++)
                <div class="sst-skeleton" aria-hidden="true"></div>
            @endfor
        </div>
        <p class="sst-empty" id="sstNoMatches" hidden>@lang('system-status::status.status_no_matches')</p>

        {{-- Incidents --}}
        <section class="sst-panel tw:mt-6">
            <header class="sst-panel-head">
                <h3 class="sst-panel-title">@lang('system-status::status.status_past_incidents')</h3>
            </header>
            <div class="sst-panel-body" id="sstIncidents">
                <p class="sst-muted tw:m-0">@lang('system-status::status.status_loading')</p>
            </div>
        </section>

        <div class="sst-tooltip" id="sstTooltip" role="tooltip" hidden></div>

        {{-- Templates: filled with textContent only --}}
        <template id="sstGroupTemplate">
            <section class="sst-group">
                <header class="sst-group-head">
                    <button type="button" class="sst-group-toggle" aria-expanded="true">
                        <i class="fas fa-chevron-down sst-chevron" aria-hidden="true"></i>
                        <h3 class="sst-group-title"></h3>
                    </button>
                    <span class="sst-group-summary"></span>
                    <span class="sst-pill sst-group-pill"></span>
                </header>
                <ul class="sst-components"></ul>
            </section>
        </template>

        <template id="sstComponentTemplate">
            <li class="sst-component">
                <div class="sst-component-head">
                    <span class="sst-dot" aria-hidden="true"></span>
                    <button type="button" class="sst-component-name"></button>
                    <span class="sst-component-uptime"></span>
                    <span class="sst-pill"></span>
                    @if($canManage)
                        <button type="button" class="sst-component-menu" aria-haspopup="menu" aria-expanded="false">
                            <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                        </button>
                    @endif
                </div>
                <p class="sst-component-message"></p>
                <div class="sst-bars"></div>
                <div class="sst-bars-legend">
                    <span class="sst-range-start"></span>
                    <span class="sst-range-end"></span>
                </div>
            </li>
        </template>

        <template id="sstMaintenanceTemplate">
            <article class="sst-incident sst-maintenance">
                <span class="sst-incident-marker" aria-hidden="true"></span>
                <div class="sst-incident-body">
                    <div class="sst-incident-head">
                        <strong class="sst-maintenance-title"></strong>
                        <span class="sst-pill sst-maintenance-state" data-status="maintenance"></span>
                        <button type="button" class="sst-link-button sst-maintenance-end" hidden></button>
                    </div>
                    <p class="sst-incident-message sst-maintenance-components"></p>
                    <p class="sst-incident-message sst-maintenance-message"></p>
                    <small class="sst-incident-time sst-maintenance-time"></small>
                </div>
            </article>
        </template>

        <template id="sstIncidentTemplate">
            <article class="sst-incident">
                <span class="sst-incident-marker" aria-hidden="true"></span>
                <div class="sst-incident-body">
                    <div class="sst-incident-head">
                        <strong class="sst-incident-component"></strong>
                        <span class="sst-pill sst-incident-severity"></span>
                        <span class="sst-incident-state"></span>
                        <span class="sst-incident-source"></span>
                    </div>
                    <p class="sst-incident-title"></p>
                    <p class="sst-incident-message"></p>
                    <small class="sst-incident-time"></small>
                    <ol class="sst-timeline"></ol>
                    <div class="sst-postmortem">
                        <strong>@lang('system-status::status.status_postmortem')</strong>
                        <p class="sst-postmortem-text"></p>
                    </div>
                    <div class="sst-incident-actions">
                        <button type="button" class="sst-link-button sst-add-update">
                            <i class="fas fa-comment-dots" aria-hidden="true"></i> @lang('system-status::status.status_add_update')
                        </button>
                        <button type="button" class="sst-link-button sst-edit-postmortem">
                            <i class="fas fa-pen" aria-hidden="true"></i> @lang('system-status::status.status_edit_postmortem')
                        </button>
                    </div>
                </div>
            </article>
        </template>
    </div>
@endsection

@push(config('system-status.stacks.modals', 'modals'))
    @include('system-status::partials.detail')
    @if($canManage)
        @include('system-status::partials.panels')
    @endif
    <div class="sst sst-ui sst-toasts" id="sstToasts" role="status" aria-live="polite"></div>
@endpush

@push(config('system-status.stacks.scripts', 'scripts'))
    @include('system-status::partials.script')
@endpush
