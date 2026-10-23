@extends($layout ?? config('system-status.layout', 'layouts.app'))

@push(config('system-status.stacks.styles', 'styles'))
    <style>{!! $styles !!}</style>
    @if(filled($extraStyles ?? null))
        <style>{!! $extraStyles !!}</style>
    @endif
@endpush

@section('title')
    @lang('system-status::status.system_status')
@endsection

@section('content')
    <div class="content sst" id="sst"
         data-summary-url="{{ route('system_status.summary') }}"
         data-component-url="{{ route('system_status.component', ['component' => '__component__']) }}"
         data-can-manage="{{ ($canManage ?? false) ? '1' : '0' }}"
         @if($canManage ?? false)
         data-csrf="{{ csrf_token() }}"
         data-maintenance-url="{{ route('system_status.maintenances.store') }}"
         data-incident-url="{{ route('system_status.incidents.store') }}"
         data-incident-update-url="{{ route('system_status.incidents.updates.store', ['incident' => '__id__']) }}"
         data-incident-edit-url="{{ route('system_status.incidents.update', ['incident' => '__id__']) }}"
         data-maintenance-end-url="{{ route('system_status.maintenances.end', ['maintenance' => '__id__']) }}"
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
                @if($canManage ?? false)
                    <button type="button" class="btn sst-refresh" data-toggle="modal" data-target="#sstIncidentModal">
                        <i class="fas fa-bullhorn" aria-hidden="true"></i>
                        <span>@lang('system-status::status.status_report_incident')</span>
                    </button>
                    <button type="button" class="btn sst-refresh" data-toggle="modal" data-target="#sstMaintenanceModal">
                        <i class="fas fa-tools" aria-hidden="true"></i>
                        <span>@lang('system-status::status.status_schedule_maintenance')</span>
                    </button>
                @endif
                <span class="sst-countdown" id="sstCountdown"></span>
                <button type="button" class="btn sst-refresh" id="sstRefresh">
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
            <ul class="sst-legend list-unstyled mb-0">
                <li><span class="sst-swatch" data-level="ok"></span>@lang('system-status::status.status_operational')</li>
                <li><span class="sst-swatch" data-level="degraded"></span>@lang('system-status::status.status_degraded')</li>
                <li><span class="sst-swatch" data-level="partial"></span>@lang('system-status::status.status_overall_partial')</li>
                <li><span class="sst-swatch" data-level="major"></span>@lang('system-status::status.status_major_outage')</li>
                <li><span class="sst-swatch" data-level="maintenance"></span>@lang('system-status::status.status_maintenance')</li>
                <li><span class="sst-swatch" data-level="none"></span>@lang('system-status::status.status_no_data')</li>
            </ul>
        </div>

        {{-- Active and upcoming maintenance --}}
        <section class="sst-panel sst-maintenance-panel mb-4" id="sstMaintenancePanel" hidden>
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
        <section class="sst-panel mt-4">
            <header class="sst-panel-head">
                <h3 class="sst-panel-title">@lang('system-status::status.status_past_incidents')</h3>
            </header>
            <div class="sst-panel-body" id="sstIncidents">
                <p class="sst-muted mb-0">@lang('system-status::status.status_loading')</p>
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
                <ul class="list-unstyled mb-0 sst-components"></ul>
            </section>
        </template>

        <template id="sstComponentTemplate">
            <li class="sst-component">
                <div class="sst-component-head">
                    <span class="sst-dot" aria-hidden="true"></span>
                    <button type="button" class="sst-component-name"></button>
                    <span class="sst-component-uptime"></span>
                    <span class="sst-pill"></span>
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
                        <button type="button" class="btn btn-link btn-sm sst-maintenance-end" hidden></button>
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
                    <ol class="sst-timeline list-unstyled"></ol>
                    <div class="sst-postmortem">
                        <strong>@lang('system-status::status.status_postmortem')</strong>
                        <p class="sst-postmortem-text"></p>
                    </div>
                    <div class="sst-incident-actions">
                        <button type="button" class="btn btn-link btn-sm sst-add-update">@lang('system-status::status.status_add_update')</button>
                        <button type="button" class="btn btn-link btn-sm sst-edit-postmortem">@lang('system-status::status.status_edit_postmortem')</button>
                    </div>
                </div>
            </article>
        </template>
    </div>
@endsection

@push(config('system-status.stacks.modals', 'modals'))
    @if($canManage ?? false)
        <div id="sstIncidentModal" class="modal fade" tabindex="-1" aria-labelledby="sstIncidentFormTitle">
            <div class="modal-dialog">
                <form class="modal-content sst sst-form" id="sstIncidentForm" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title" id="sstIncidentFormTitle">@lang('system-status::status.status_report_incident')</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('system-status::status.close') }}">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="sst-form-hint">@lang('system-status::status.status_incident_hint')</p>
                        <div class="alert alert-danger sst-form-error" hidden></div>
                        <div class="form-group">
                            <label for="sstIncidentComponent">@lang('system-status::status.status_form_component')</label>
                            <select class="form-control" id="sstIncidentComponent" name="component" required>
                                @foreach(($componentOptions ?? []) as $group)
                                    <optgroup label="{{ $group['label'] }}">
                                        @foreach($group['components'] as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-sm-6">
                                <label for="sstIncidentSeverity">@lang('system-status::status.status_form_severity')</label>
                                <select class="form-control" id="sstIncidentSeverity" name="severity">
                                    <option value="degraded">@lang('system-status::status.status_degraded')</option>
                                    <option value="major_outage">@lang('system-status::status.status_major_outage')</option>
                                </select>
                            </div>
                            <div class="form-group col-sm-6">
                                <label for="sstIncidentState">@lang('system-status::status.status_form_state')</label>
                                <select class="form-control" id="sstIncidentState" name="state">
                                    @foreach(['investigating', 'identified', 'monitoring'] as $incidentState)
                                        <option value="{{ $incidentState }}">@lang('system-status::status.status_state_'.$incidentState)</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="sstIncidentTitle">@lang('system-status::status.status_form_title')</label>
                            <input type="text" class="form-control" id="sstIncidentTitle" name="title" maxlength="200" required
                                   placeholder="{{ __('system-status::status.status_incident_title_placeholder') }}">
                        </div>
                        <div class="form-group mb-0">
                            <label for="sstIncidentMessage">@lang('system-status::status.status_form_message')</label>
                            <textarea class="form-control" id="sstIncidentMessage" name="message" rows="3" maxlength="500"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">@lang('system-status::status.close')</button>
                        <button type="submit" class="btn btn-primary sst-submit">@lang('system-status::status.status_form_post')</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="sstUpdateModal" class="modal fade" tabindex="-1" aria-labelledby="sstUpdateFormTitle">
            <div class="modal-dialog">
                <form class="modal-content sst sst-form" id="sstUpdateForm" novalidate>
                    <input type="hidden" name="incident_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="sstUpdateFormTitle">@lang('system-status::status.status_add_update')</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('system-status::status.close') }}">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="sst-form-hint sst-update-for"></p>
                        <div class="alert alert-danger sst-form-error" hidden></div>
                        <div class="form-group">
                            <label for="sstUpdateState">@lang('system-status::status.status_form_state')</label>
                            <select class="form-control" id="sstUpdateState" name="state">
                                @foreach(['investigating', 'identified', 'monitoring', 'resolved'] as $incidentState)
                                    <option value="{{ $incidentState }}">@lang('system-status::status.status_state_'.$incidentState)</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label for="sstUpdateMessage">@lang('system-status::status.status_message')</label>
                            <textarea class="form-control" id="sstUpdateMessage" name="message" rows="3" maxlength="2000" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">@lang('system-status::status.close')</button>
                        <button type="submit" class="btn btn-primary sst-submit">@lang('system-status::status.status_form_post')</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="sstPostmortemModal" class="modal fade" tabindex="-1" aria-labelledby="sstPostmortemFormTitle">
            <div class="modal-dialog modal-lg">
                <form class="modal-content sst sst-form" id="sstPostmortemForm" novalidate>
                    <input type="hidden" name="incident_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="sstPostmortemFormTitle">@lang('system-status::status.status_postmortem')</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('system-status::status.close') }}">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="sst-form-hint">@lang('system-status::status.status_postmortem_hint')</p>
                        <div class="alert alert-danger sst-form-error" hidden></div>
                        <textarea class="form-control" name="postmortem" rows="8" maxlength="10000"
                                  aria-label="{{ __('system-status::status.status_postmortem') }}"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">@lang('system-status::status.close')</button>
                        <button type="submit" class="btn btn-primary sst-submit">@lang('system-status::status.status_form_save')</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if($canManage ?? false)
        <div id="sstMaintenanceModal" class="modal fade" tabindex="-1" aria-labelledby="sstMaintenanceTitle">
            <div class="modal-dialog modal-lg">
                <form class="modal-content sst sst-form" id="sstMaintenanceForm" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title" id="sstMaintenanceTitle">@lang('system-status::status.status_schedule_maintenance')</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('system-status::status.close') }}">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="sst-form-hint">@lang('system-status::status.status_maintenance_hint')</p>
                        <div class="alert alert-danger sst-form-error" hidden></div>
                        <div class="form-group">
                            <label for="sstMaintenanceTitleInput">@lang('system-status::status.status_form_title')</label>
                            <input type="text" class="form-control" id="sstMaintenanceTitleInput" name="title" maxlength="200" required
                                   value="{{ __('system-status::status.status_maintenance_default_title') }}">
                        </div>
                        <div class="form-row">
                            <div class="form-group col-sm-6">
                                <label for="sstMaintenanceStart">@lang('system-status::status.status_form_starts_at')</label>
                                <input type="datetime-local" class="form-control" id="sstMaintenanceStart" name="starts_at" required>
                            </div>
                            <div class="form-group col-sm-6">
                                <label for="sstMaintenanceEnd">@lang('system-status::status.status_form_ends_at')</label>
                                <input type="datetime-local" class="form-control" id="sstMaintenanceEnd" name="ends_at" required>
                            </div>
                        </div>
                        <fieldset class="form-group">
                            <legend class="col-form-label">@lang('system-status::status.status_form_components')</legend>
                            <div class="sst-component-picker">
                                @foreach(($componentOptions ?? []) as $group)
                                    <div class="sst-picker-group">
                                        <strong>{{ $group['label'] }}</strong>
                                        @foreach($group['components'] as $key => $label)
                                            <label class="sst-check">
                                                <input type="checkbox" name="components[]" value="{{ $key }}">
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="form-group mb-0">
                            <label for="sstMaintenanceMessage">@lang('system-status::status.status_form_message')</label>
                            <textarea class="form-control" id="sstMaintenanceMessage" name="message" rows="3" maxlength="2000"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">@lang('system-status::status.close')</button>
                        <button type="submit" class="btn btn-primary sst-submit">@lang('system-status::status.status_form_schedule')</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div id="sstDetailModal" class="modal fade" tabindex="-1" aria-labelledby="sstDetailTitle">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content sst sst-detail">
                <div class="modal-header sst-detail-head">
                    <div class="sst-detail-heading">
                        <span class="sst-dot" id="sstDetailDot" aria-hidden="true"></span>
                        <h5 class="modal-title" id="sstDetailTitle"></h5>
                        <span class="sst-pill" id="sstDetailPill"></span>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('system-status::status.close') }}">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="sst-detail-message" id="sstDetailMessage" hidden></p>

                    <div class="sst-kpis sst-kpis-compact">
                        <div class="sst-kpi">
                            <span class="sst-kpi-label">@lang('system-status::status.status_requests_24h')</span>
                            <strong class="sst-kpi-value" id="sstDetailRequests">—</strong>
                        </div>
                        <div class="sst-kpi" data-tone="danger">
                            <span class="sst-kpi-label">@lang('system-status::status.status_errors')</span>
                            <strong class="sst-kpi-value" id="sstDetailErrors">—</strong>
                        </div>
                        <div class="sst-kpi" data-tone="warning">
                            <span class="sst-kpi-label">@lang('system-status::status.status_error_rate')</span>
                            <strong class="sst-kpi-value" id="sstDetailRate">—</strong>
                        </div>
                        <div class="sst-kpi">
                            <span class="sst-kpi-label">@lang('system-status::status.status_avg_response')</span>
                            <strong class="sst-kpi-value" id="sstDetailAvg">—</strong>
                        </div>
                    </div>

                    <section class="sst-panel">
                        <header class="sst-panel-head">
                            <h3 class="sst-panel-title">@lang('system-status::status.status_requests_24h')</h3>
                            <ul class="sst-legend list-unstyled mb-0">
                                <li><span class="sst-swatch" data-level="requests"></span>@lang('system-status::status.status_requests')</li>
                                <li><span class="sst-swatch" data-level="major"></span>@lang('system-status::status.status_errors')</li>
                            </ul>
                        </header>
                        <div class="sst-panel-body">
                            <div class="sst-chart" id="sstDetailChart"></div>
                            <div class="sst-bars-legend">
                                <span>@lang('system-status::status.status_hours_ago')</span>
                                <span>@lang('system-status::status.status_now')</span>
                            </div>
                        </div>
                    </section>

                    <div class="sst-signal" id="sstDetailSignal" hidden></div>

                    <section class="sst-panel">
                        <header class="sst-panel-head">
                            <h3 class="sst-panel-title">@lang('system-status::status.status_top_routes')</h3>
                        </header>
                        <div class="table-responsive">
                            <table class="table sst-table mb-0">
                                <thead>
                                <tr>
                                    <th>@lang('system-status::status.status_route')</th>
                                    <th class="sst-num">@lang('system-status::status.status_requests')</th>
                                    <th class="sst-num">@lang('system-status::status.status_errors')</th>
                                    <th class="sst-num">@lang('system-status::status.status_error_rate')</th>
                                    <th class="sst-num">@lang('system-status::status.status_slow')</th>
                                    <th class="sst-num">@lang('system-status::status.status_avg_ms')</th>
                                </tr>
                                </thead>
                                <tbody id="sstDetailRoutes"></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="sst-panel">
                        <header class="sst-panel-head">
                            <h3 class="sst-panel-title">@lang('system-status::status.status_recent_checks')</h3>
                        </header>
                        <div class="table-responsive">
                            <table class="table sst-table mb-0">
                                <thead>
                                <tr>
                                    <th>@lang('system-status::status.status_checked_at')</th>
                                    <th>@lang('system-status::status.status_probe')</th>
                                    <th>@lang('system-status::status.status')</th>
                                    <th class="sst-num">@lang('system-status::status.status_response_ms')</th>
                                    <th>@lang('system-status::status.status_message')</th>
                                </tr>
                                </thead>
                                <tbody id="sstDetailChecks"></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="sst-panel">
                        <header class="sst-panel-head">
                            <h3 class="sst-panel-title">@lang('system-status::status.status_incidents')</h3>
                        </header>
                        <div class="sst-panel-body" id="sstDetailIncidents"></div>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endpush

@push(config('system-status.stacks.scripts', 'scripts'))
    <script>
        (function ($) {
            'use strict';

            const root = document.getElementById('sst');
            if (!root) {
                return;
            }

            const RANGES = ['1h', '24h', '7d', '90d'];
            const LEVEL_ICONS = {
                operational: 'fas fa-check',
                degraded: 'fas fa-exclamation',
                partial: 'fas fa-exclamation-triangle',
                major: 'fas fa-times',
                maintenance: 'fas fa-tools',
                loading: 'fas fa-circle-notch',
            };
            const i18n = {
                lastChecked: @json(__('system-status::status.status_last_checked', ['time' => ':time'])),
                uptime: @json(__('system-status::status.status_uptime', ['value' => ':value'])),
                noData: @json(__('system-status::status.status_no_data')),
                noIncidents: @json(__('system-status::status.status_no_incidents')),
                ongoing: @json(__('system-status::status.status_ongoing')),
                resolved: @json(__('system-status::status.status_resolved')),
                duration: @json(__('system-status::status.status_duration_minutes', ['minutes' => ':minutes'])),
                outageMinutes: @json(__('system-status::status.status_outage_minutes', ['minutes' => ':minutes'])),
                degradedMinutes: @json(__('system-status::status.status_degraded_minutes', ['minutes' => ':minutes'])),
                noTraffic: @json(__('system-status::status.status_no_traffic')),
                signals: @json(__('system-status::status.status_signals_24h', ['failures' => ':failures', 'calls' => ':calls'])),
                lastFailure: @json(__('system-status::status.status_last_failure', ['reason' => ':reason', 'time' => ':time'])),
                loadFailed: @json(__('system-status::status.status_load_failed')),
                requests: @json(__('system-status::status.status_requests')),
                errors: @json(__('system-status::status.status_errors')),
                groupSummary: @json(__('system-status::status.status_group_summary', ['ok' => ':ok', 'total' => ':total'])),
                nextRefresh: @json(__('system-status::status.status_next_refresh', ['seconds' => ':seconds'])),
                operational: @json(__('system-status::status.status_operational')),
                degraded: @json(__('system-status::status.status_degraded')),
                majorOutage: @json(__('system-status::status.status_major_outage')),
                partialOutage: @json(__('system-status::status.status_overall_partial')),
                maintenance: @json(__('system-status::status.status_maintenance')),
                maintenanceMinutes: @json(__('system-status::status.status_maintenance_minutes', ['minutes' => ':minutes'])),
                maintenanceActive: @json(__('system-status::status.status_maintenance_active')),
                maintenanceUpcoming: @json(__('system-status::status.status_maintenance_upcoming')),
                maintenanceEnd: @json(__('system-status::status.status_maintenance_end')),
                maintenanceCancel: @json(__('system-status::status.status_maintenance_cancel')),
                affects: @json(__('system-status::status.status_affects', ['components' => ':components'])),
                by: @json(__('system-status::status.status_by', ['name' => ':name'])),
                saveFailed: @json(__('system-status::status.status_save_failed')),
                manual: @json(__('system-status::status.status_source_manual')),
                updateFor: @json(__('system-status::status.status_update_for', ['title' => ':title'])),
                today: @json(__('system-status::status.status_today')),
                now: @json(__('system-status::status.status_now')),
            };
            const numberFormat = new Intl.NumberFormat(document.documentElement.lang || undefined);

            const state = {filter: 'all', search: '', range: readRange(), data: null, countdown: 0, loading: false};

            function readRange() {
                try {
                    const saved = localStorage.getItem('sst.range');
                    return RANGES.indexOf(saved) !== -1 ? saved : '90d';
                } catch (e) {
                    return '90d';
                }
            }

            // Real time refreshes every 20 s (checks run every minute); the other ranges every 60 s.
            const REFRESH = @json((array) config('system-status.refresh_seconds', ['1h' => 20, 'default' => 60]));

            function refreshSeconds() {
                return Math.max(5, parseInt(REFRESH[state.range] || REFRESH.default || 60, 10));
            }

            /* ---------- helpers ---------- */
            function fill(template, values) {
                return Object.keys(values).reduce(function (text, key) {
                    return text.split(':' + key).join(values[key]);
                }, template);
            }

            function setText(parent, selector, text) {
                const node = parent.querySelector(selector);
                if (node) {
                    node.textContent = text == null ? '' : String(text);
                }
                return node;
            }

            function setPill(node, status, label) {
                node.dataset.status = status;
                node.textContent = label;
            }

            function isProblem(component) {
                return component.configured && component.status !== 'operational' && component.status !== 'maintenance';
            }

            // Maintenance only shows for a group when nothing in it is worse.
            function worstStatus(components) {
                const order = {operational: 0, maintenance: 0.5, degraded: 1, major_outage: 2};
                return components.reduce(function (worst, c) {
                    return c.configured && (order[c.status] || 0) > order[worst] ? c.status : worst;
                }, 'operational');
            }

            function statusLabel(status) {
                return {operational: i18n.operational, degraded: i18n.degraded, major_outage: i18n.majorOutage, maintenance: i18n.maintenance}[status] || status;
            }

            /* ---------- tooltip ---------- */
            const tooltip = document.getElementById('sstTooltip');

            function showTooltip(target, lines) {
                tooltip.replaceChildren();
                lines.forEach(function (line, index) {
                    const row = document.createElement(index === 0 ? 'strong' : 'span');
                    row.textContent = line;
                    tooltip.appendChild(row);
                });
                tooltip.hidden = false;

                const rect = target.getBoundingClientRect();
                const tip = tooltip.getBoundingClientRect();
                const left = Math.min(Math.max(8, rect.left + rect.width / 2 - tip.width / 2), window.innerWidth - tip.width - 8);
                const top = rect.top - tip.height - 8 < 8 ? rect.bottom + 8 : rect.top - tip.height - 8;
                tooltip.style.left = left + 'px';
                tooltip.style.top = top + 'px';
            }

            function hideTooltip() {
                tooltip.hidden = true;
            }

            function slotLines(slot) {
                const lines = [slot.label];
                if (slot.level === 'none') {
                    lines.push(i18n.noData);
                } else {
                    lines.push(fill(i18n.uptime, {value: slot.uptime}));
                }
                if (slot.outage_minutes > 0) {
                    lines.push(fill(i18n.outageMinutes, {minutes: slot.outage_minutes}));
                }
                if (slot.degraded_minutes > 0) {
                    lines.push(fill(i18n.degradedMinutes, {minutes: slot.degraded_minutes}));
                }
                if (slot.maintenance_minutes > 0) {
                    lines.push(fill(i18n.maintenanceMinutes, {minutes: slot.maintenance_minutes}));
                }
                if (slot.requests !== null && slot.requests !== undefined) {
                    lines.push(i18n.requests + ': ' + numberFormat.format(slot.requests) + ' · ' + i18n.errors + ': ' + numberFormat.format(slot.errors || 0));
                }
                return lines;
            }

            $(document)
                .on('mouseenter', '.sst-bar', function () {
                    const lines = this._sstLines;
                    if (lines) {
                        showTooltip(this, lines);
                    }
                })
                .on('mouseleave', '.sst-bar', hideTooltip);
            window.addEventListener('scroll', hideTooltip, {passive: true});

            /* ---------- rendering ---------- */
            function renderBars(container, days) {
                const fragment = document.createDocumentFragment();
                days.forEach(function (day) {
                    const bar = document.createElement('span');
                    bar.className = 'sst-bar';
                    bar.dataset.level = day.level;
                    bar._sstLines = slotLines(day);
                    fragment.appendChild(bar);
                });
                container.replaceChildren(fragment);
            }

            function renderHero(data) {
                const level = data.stale ? 'major' : data.overall.level;
                const hero = document.getElementById('sstHero');
                hero.dataset.level = level;
                document.getElementById('sstHeroIcon').className = LEVEL_ICONS[level] || LEVEL_ICONS.loading;
                document.getElementById('sstHeroTitle').textContent = data.overall.label;
                document.getElementById('sstHeroMeta').textContent = data.last_run_ago
                    ? fill(i18n.lastChecked, {time: data.last_run_ago}) + (data.last_run_at ? ' · ' + data.last_run_at : '')
                    : '';
            }

            function renderKpis(data) {
                const components = [];
                data.groups.forEach(function (group) {
                    group.components.forEach(function (c) {
                        components.push(c);
                    });
                });
                const configured = components.filter(function (c) { return c.configured; });
                const withUptime = configured.filter(function (c) { return c.uptime !== null; });
                const uptime = withUptime.length
                    ? withUptime.reduce(function (sum, c) { return sum + c.uptime; }, 0) / withUptime.length
                    : null;
                const problems = configured.filter(isProblem).length;

                document.getElementById('sstKpiUptime').textContent = uptime === null ? '—' : uptime.toFixed(2) + '%';
                document.getElementById('sstKpiUptimeLabel').textContent = data.range.uptime_label;
                document.getElementById('sstKpiIncidents').textContent = numberFormat.format(data.incidents.filter(function (i) { return i.ongoing; }).length);
                document.getElementById('sstKpiAffected').textContent = numberFormat.format(problems);
                document.getElementById('sstKpiComponents').textContent = numberFormat.format(configured.length);
                const problemCount = document.getElementById('sstProblemCount');
                problemCount.textContent = numberFormat.format(problems);
                problemCount.dataset.zero = problems === 0 ? '1' : '0';
            }

            function renderGroups(groups) {
                const groupTemplate = document.getElementById('sstGroupTemplate');
                const componentTemplate = document.getElementById('sstComponentTemplate');
                const fragment = document.createDocumentFragment();
                const collapsed = readCollapsed();

                groups.forEach(function (group) {
                    const groupNode = groupTemplate.content.firstElementChild.cloneNode(true);
                    const configured = group.components.filter(function (c) { return c.configured; });
                    const ok = configured.filter(function (c) { return c.status === 'operational'; }).length;
                    const worst = worstStatus(group.components);

                    groupNode.dataset.group = group.key;
                    groupNode.dataset.status = worst;
                    setText(groupNode, '.sst-group-title', group.label);
                    setText(groupNode, '.sst-group-summary', fill(i18n.groupSummary, {ok: ok, total: configured.length}));
                    setPill(groupNode.querySelector('.sst-group-pill'), worst, statusLabel(worst));

                    const isCollapsed = collapsed.indexOf(group.key) !== -1;
                    groupNode.classList.toggle('is-collapsed', isCollapsed);
                    groupNode.querySelector('.sst-group-toggle').setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');

                    const list = groupNode.querySelector('.sst-components');
                    group.components.forEach(function (component) {
                        const node = componentTemplate.content.firstElementChild.cloneNode(true);
                        const status = component.configured ? component.status : 'not_configured';
                        node.dataset.status = status;
                        node.dataset.problem = isProblem(component) ? '1' : '0';
                        node.dataset.search = (component.label + ' ' + component.key).toLowerCase();

                        const name = setText(node, '.sst-component-name', component.label);
                        name.dataset.component = component.key;
                        setPill(node.querySelector('.sst-pill'), status, component.status_label);
                        setText(node, '.sst-component-uptime', component.uptime === null ? '' : fill(i18n.uptime, {value: component.uptime}));

                        const message = setText(node, '.sst-component-message', component.message || '');
                        message.hidden = !component.message;

                        renderBars(node.querySelector('.sst-bars'), component.days);
                        setText(node, '.sst-range-start', state.data.range.start_label);
                        setText(node, '.sst-range-end', state.data.range.key === '90d' ? i18n.today : i18n.now);
                        list.appendChild(node);
                    });

                    fragment.appendChild(groupNode);
                });

                document.getElementById('sstGroups').replaceChildren(fragment);
                applyFilter();
            }

            // manage: show the update / postmortem buttons (not inside the detail modal).
            function renderIncidents(container, incidents, manage) {
                if (!incidents.length) {
                    const empty = document.createElement('p');
                    empty.className = 'sst-empty-state';
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-check-circle';
                    icon.setAttribute('aria-hidden', 'true');
                    const text = document.createElement('span');
                    text.textContent = i18n.noIncidents;
                    empty.append(icon, text);
                    container.replaceChildren(empty);
                    return;
                }

                const template = document.getElementById('sstIncidentTemplate');
                const fragment = document.createDocumentFragment();

                const canManage = manage && root.dataset.canManage === '1';

                incidents.forEach(function (incident) {
                    const node = template.content.firstElementChild.cloneNode(true);
                    node.dataset.ongoing = incident.ongoing ? '1' : '0';
                    node.dataset.status = incident.severity;
                    node.dataset.id = incident.id;
                    setText(node, '.sst-incident-component', incident.component_label);
                    setPill(node.querySelector('.sst-incident-severity'), incident.severity, incident.severity_label);
                    setText(node, '.sst-incident-state', incident.ongoing ? (incident.state_label || i18n.ongoing) : i18n.resolved);
                    const source = setText(node, '.sst-incident-source', incident.source === 'manual' ? i18n.manual : '');
                    source.hidden = incident.source !== 'manual';
                    const title = setText(node, '.sst-incident-title', incident.title || '');
                    title.hidden = !incident.title;
                    // The first timeline entry repeats the message of a manual incident.
                    const showMessage = incident.message && !(incident.updates || []).length;
                    const message = setText(node, '.sst-incident-message', showMessage ? incident.message : '');
                    message.hidden = !showMessage;
                    setText(node, '.sst-incident-time',
                        incident.started_at + ' → ' + (incident.resolved_at || '…') + ' · ' + fill(i18n.duration, {minutes: incident.duration_minutes})
                        + (incident.created_by ? ' · ' + fill(i18n.by, {name: incident.created_by}) : ''));

                    const timeline = node.querySelector('.sst-timeline');
                    (incident.updates || []).forEach(function (update) {
                        const item = document.createElement('li');
                        item.dataset.state = update.state;
                        const head = document.createElement('strong');
                        head.textContent = update.state_label;
                        const text = document.createElement('span');
                        text.textContent = update.message;
                        const meta = document.createElement('small');
                        meta.textContent = update.at + (update.author ? ' · ' + update.author : '');
                        item.append(head, text, meta);
                        timeline.appendChild(item);
                    });
                    timeline.hidden = !timeline.children.length;

                    const postmortem = node.querySelector('.sst-postmortem');
                    setText(node, '.sst-postmortem-text', incident.postmortem || '');
                    postmortem.hidden = !incident.postmortem;

                    const addUpdate = node.querySelector('.sst-add-update');
                    const editPostmortem = node.querySelector('.sst-edit-postmortem');
                    addUpdate.hidden = !(canManage && incident.ongoing);
                    editPostmortem.hidden = !(canManage && !incident.ongoing);
                    node.querySelector('.sst-incident-actions').hidden = addUpdate.hidden && editPostmortem.hidden;
                    node._sstIncident = incident;

                    fragment.appendChild(node);
                });

                container.replaceChildren(fragment);
            }

            function renderMaintenances(list) {
                const panel = document.getElementById('sstMaintenancePanel');
                const container = document.getElementById('sstMaintenances');
                panel.hidden = !list.length;

                const template = document.getElementById('sstMaintenanceTemplate');
                const fragment = document.createDocumentFragment();
                const canManage = root.dataset.canManage === '1';

                list.forEach(function (item) {
                    const node = template.content.firstElementChild.cloneNode(true);
                    node.dataset.active = item.active ? '1' : '0';
                    node.dataset.status = 'maintenance';
                    setText(node, '.sst-maintenance-title', item.title);
                    setText(node, '.sst-maintenance-state', item.active ? i18n.maintenanceActive : i18n.maintenanceUpcoming);
                    setText(node, '.sst-maintenance-components', fill(i18n.affects, {
                        components: item.components.map(function (c) { return c.label; }).join(', '),
                    }));
                    const message = setText(node, '.sst-maintenance-message', item.message || '');
                    message.hidden = !item.message;
                    setText(node, '.sst-maintenance-time', item.starts_at + ' → ' + item.ends_at
                        + (item.created_by ? ' · ' + fill(i18n.by, {name: item.created_by}) : ''));

                    const end = node.querySelector('.sst-maintenance-end');
                    if (canManage) {
                        end.hidden = false;
                        end.textContent = item.active ? i18n.maintenanceEnd : i18n.maintenanceCancel;
                        end.dataset.id = item.id;
                    }
                    fragment.appendChild(node);
                });

                container.replaceChildren(fragment);
            }

            /* ---------- write actions ---------- */
            function send(method, url, data) {
                return $.ajax({
                    url: url,
                    method: method,
                    data: data ? JSON.stringify(data) : undefined,
                    contentType: 'application/json',
                    dataType: 'json',
                    headers: {'X-CSRF-TOKEN': root.dataset.csrf || '', 'Accept': 'application/json'},
                });
            }

            function showFormError(form, xhr) {
                const box = form.querySelector('.sst-form-error');
                const errors = xhr && xhr.responseJSON && xhr.responseJSON.errors;
                const lines = errors ? Object.keys(errors).map(function (key) { return errors[key][0]; }) : [i18n.saveFailed];
                box.replaceChildren();
                lines.forEach(function (line) {
                    const p = document.createElement('div');
                    p.textContent = line;
                    box.appendChild(p);
                });
                box.hidden = false;
            }

            function formData(form) {
                const data = {};
                new FormData(form).forEach(function (value, key) {
                    if (key.slice(-2) === '[]') {
                        key = key.slice(0, -2);
                        (data[key] = data[key] || []).push(value);
                    } else {
                        data[key] = value;
                    }
                });
                return data;
            }

            // datetime-local is the viewer's local time; send it with its offset.
            function isoFromLocal(value) {
                return value ? new Date(value).toISOString() : value;
            }

            function localInputValue(date) {
                const pad = function (n) { return String(n).padStart(2, '0'); };
                return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                    + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
            }

            function submitForm(form, method, url, prepare, done) {
                const button = form.querySelector('.sst-submit');
                const data = prepare(formData(form));
                form.querySelector('.sst-form-error').hidden = true;
                button.disabled = true;

                send(method, url, data)
                    .done(function () {
                        $(form.closest('.modal')).modal('hide');
                        form.reset();
                        if (done) {
                            done();
                        }
                        load();
                    })
                    .fail(function (xhr) { showFormError(form, xhr); })
                    .always(function () { button.disabled = false; });
            }

            $('#sstMaintenanceModal').on('show.bs.modal', function () {
                const now = new Date();
                document.getElementById('sstMaintenanceStart').value = localInputValue(now);
                document.getElementById('sstMaintenanceEnd').value = localInputValue(new Date(now.getTime() + 30 * 60000));
                this.querySelector('.sst-form-error').hidden = true;
            });

            $(document).on('submit', '#sstMaintenanceForm', function (event) {
                event.preventDefault();
                submitForm(this, 'POST', root.dataset.maintenanceUrl, function (data) {
                    data.starts_at = isoFromLocal(data.starts_at);
                    data.ends_at = isoFromLocal(data.ends_at);
                    return data;
                });
            });

            $(document).on('submit', '#sstIncidentForm', function (event) {
                event.preventDefault();
                submitForm(this, 'POST', root.dataset.incidentUrl, function (data) { return data; });
            });

            $(document).on('click', '.sst-add-update', function () {
                const incident = this.closest('.sst-incident')._sstIncident;
                const form = document.getElementById('sstUpdateForm');
                form.reset();
                form.elements.incident_id.value = incident.id;
                form.querySelector('.sst-update-for').textContent = fill(i18n.updateFor, {title: incident.title || incident.component_label});
                // Only incidents posted by hand are resolved by a person.
                form.querySelector('option[value="resolved"]').hidden = incident.source !== 'manual';
                form.querySelector('option[value="resolved"]').disabled = incident.source !== 'manual';
                form.elements.state.value = incident.state && incident.state !== 'resolved' ? incident.state : 'investigating';
                form.querySelector('.sst-form-error').hidden = true;
                $('#sstUpdateModal').modal('show');
            });

            $(document).on('submit', '#sstUpdateForm', function (event) {
                event.preventDefault();
                const url = root.dataset.incidentUpdateUrl.replace('__id__', this.elements.incident_id.value);
                submitForm(this, 'POST', url, function (data) {
                    delete data.incident_id;
                    return data;
                });
            });

            $(document).on('click', '.sst-edit-postmortem', function () {
                const incident = this.closest('.sst-incident')._sstIncident;
                const form = document.getElementById('sstPostmortemForm');
                form.elements.incident_id.value = incident.id;
                form.elements.postmortem.value = incident.postmortem || '';
                form.querySelector('.sst-form-error').hidden = true;
                $('#sstPostmortemModal').modal('show');
            });

            $(document).on('submit', '#sstPostmortemForm', function (event) {
                event.preventDefault();
                const url = root.dataset.incidentEditUrl.replace('__id__', this.elements.incident_id.value);
                submitForm(this, 'PATCH', url, function (data) {
                    delete data.incident_id;
                    return data;
                });
            });

            $(document).on('click', '.sst-maintenance-end', function () {
                const button = this;
                button.disabled = true;
                send('DELETE', root.dataset.maintenanceEndUrl.replace('__id__', button.dataset.id))
                    .done(load)
                    .fail(function () { button.disabled = false; });
            });

            /* ---------- filter, search, collapse ---------- */
            function applyFilter() {
                let visible = 0;
                document.querySelectorAll('#sstGroups .sst-group').forEach(function (groupNode) {
                    let groupVisible = 0;
                    groupNode.querySelectorAll('.sst-component').forEach(function (node) {
                        const show = (state.filter === 'all' || node.dataset.problem === '1')
                            && (!state.search || node.dataset.search.indexOf(state.search) !== -1);
                        node.hidden = !show;
                        groupVisible += show ? 1 : 0;
                    });
                    groupNode.hidden = groupVisible === 0;
                    visible += groupVisible;
                });
                document.getElementById('sstNoMatches').hidden = visible > 0 || !state.data;
            }

            function readCollapsed() {
                try {
                    return JSON.parse(localStorage.getItem('sst.collapsed') || '[]');
                } catch (e) {
                    return [];
                }
            }

            function writeCollapsed(list) {
                try {
                    localStorage.setItem('sst.collapsed', JSON.stringify(list));
                } catch (e) {
                    // Remembering collapsed groups is only a convenience.
                }
            }

            $(document).on('click', '.sst-segment[data-filter]', function () {
                state.filter = this.dataset.filter;
                document.querySelectorAll('.sst-segment[data-filter]').forEach(function (button) {
                    button.setAttribute('aria-pressed', button.dataset.filter === state.filter ? 'true' : 'false');
                });
                applyFilter();
            });

            function markRange() {
                document.querySelectorAll('.sst-segment[data-range]').forEach(function (button) {
                    button.setAttribute('aria-pressed', button.dataset.range === state.range ? 'true' : 'false');
                });
                root.dataset.range = state.range;
            }

            $(document).on('click', '.sst-segment[data-range]', function () {
                if (this.dataset.range === state.range) {
                    return;
                }
                state.range = this.dataset.range;
                try {
                    localStorage.setItem('sst.range', state.range);
                } catch (e) {
                    // Remembering the range is only a convenience.
                }
                markRange();
                load();
            });

            document.getElementById('sstSearch').addEventListener('input', function () {
                state.search = this.value.trim().toLowerCase();
                applyFilter();
            });

            $(document).on('click', '.sst-group-toggle', function () {
                const groupNode = this.closest('.sst-group');
                const collapsed = !groupNode.classList.contains('is-collapsed');
                groupNode.classList.toggle('is-collapsed', collapsed);
                this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

                const list = readCollapsed().filter(function (key) { return key !== groupNode.dataset.group; });
                if (collapsed) {
                    list.push(groupNode.dataset.group);
                }
                writeCollapsed(list);
            });

            /* ---------- loading ---------- */
            function load() {
                if (state.loading) {
                    return;
                }
                state.loading = true;
                root.classList.add('is-refreshing');

                $.get(root.dataset.summaryUrl, {range: state.range})
                    .done(function (response) {
                        state.data = response.data;
                        renderHero(state.data);
                        renderKpis(state.data);
                        renderGroups(state.data.groups);
                        renderIncidents(document.getElementById('sstIncidents'), state.data.incidents, true);
                        renderMaintenances(state.data.maintenances || []);
                    })
                    .fail(function () {
                        const hero = document.getElementById('sstHero');
                        hero.dataset.level = 'major';
                        document.getElementById('sstHeroIcon').className = LEVEL_ICONS.major;
                        document.getElementById('sstHeroTitle').textContent = i18n.loadFailed;
                    })
                    .always(function () {
                        state.loading = false;
                        state.countdown = refreshSeconds();
                        root.classList.remove('is-refreshing');
                        tick();
                    });
            }

            function tick() {
                document.getElementById('sstCountdown').textContent = fill(i18n.nextRefresh, {seconds: state.countdown});
            }

            setInterval(function () {
                if (document.hidden || state.loading) {
                    return;
                }
                state.countdown -= 1;
                if (state.countdown <= 0) {
                    load();
                    return;
                }
                tick();
            }, 1000);

            document.getElementById('sstRefresh').addEventListener('click', load);

            /* ---------- component detail ---------- */
            function row(cells, numericFrom) {
                const tr = document.createElement('tr');
                cells.forEach(function (value, index) {
                    const td = document.createElement('td');
                    td.textContent = value == null ? '' : String(value);
                    if (numericFrom !== undefined && index >= numericFrom) {
                        td.className = 'sst-num';
                    }
                    tr.appendChild(td);
                });
                return tr;
            }

            function emptyRow(columns, text) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.colSpan = columns;
                td.className = 'sst-muted text-center';
                td.textContent = text;
                tr.appendChild(td);
                return tr;
            }

            function renderChart(container, hourly) {
                const max = Math.max.apply(null, hourly.map(function (h) { return h.requests; }));
                if (!max) {
                    const empty = document.createElement('p');
                    empty.className = 'sst-muted mb-0 text-center py-4';
                    empty.textContent = i18n.noTraffic;
                    container.replaceChildren(empty);
                    return;
                }

                const fragment = document.createDocumentFragment();
                hourly.forEach(function (hour) {
                    const column = document.createElement('span');
                    column.className = 'sst-chart-col sst-bar';
                    column._sstLines = [hour.hour, i18n.requests + ': ' + numberFormat.format(hour.requests), i18n.errors + ': ' + numberFormat.format(hour.errors)];

                    const total = document.createElement('span');
                    total.className = 'sst-chart-total';
                    total.style.blockSize = (hour.requests ? Math.max(3, hour.requests / max * 100) : 0) + '%';

                    const errors = document.createElement('span');
                    errors.className = 'sst-chart-errors';
                    errors.style.blockSize = (hour.errors ? Math.max(6, hour.errors / Math.max(hour.requests, 1) * 100) : 0) + '%';

                    total.appendChild(errors);
                    column.appendChild(total);
                    fragment.appendChild(column);
                });
                container.replaceChildren(fragment);
            }

            function resetDetail() {
                ['sstDetailTitle', 'sstDetailRequests', 'sstDetailErrors', 'sstDetailRate', 'sstDetailAvg'].forEach(function (id) {
                    document.getElementById(id).textContent = id === 'sstDetailTitle' ? '' : '—';
                });
                document.getElementById('sstDetailPill').textContent = '';
                document.getElementById('sstDetailMessage').hidden = true;
                document.getElementById('sstDetailSignal').hidden = true;
                ['sstDetailRoutes', 'sstDetailChecks', 'sstDetailIncidents'].forEach(function (id) {
                    document.getElementById(id).replaceChildren();
                });
                const chart = document.getElementById('sstDetailChart');
                chart.replaceChildren();
                chart.classList.add('is-loading');
            }

            function openDetail(component) {
                resetDetail();
                $('#sstDetailModal').modal('show');

                $.get(root.dataset.componentUrl.replace('__component__', encodeURIComponent(component)))
                    .done(function (response) {
                        const data = response.data;
                        const requests = data.hourly.reduce(function (sum, h) { return sum + h.requests; }, 0);
                        const errors = data.hourly.reduce(function (sum, h) { return sum + h.errors; }, 0);
                        const routeRequests = data.routes.reduce(function (sum, r) { return sum + r.requests; }, 0);
                        const weightedMs = data.routes.reduce(function (sum, r) { return sum + r.avg_ms * r.requests; }, 0);

                        document.getElementById('sstDetailTitle').textContent = data.label;
                        document.getElementById('sstDetailDot').dataset.status = data.status;
                        setPill(document.getElementById('sstDetailPill'), data.status, data.status_label);

                        const message = document.getElementById('sstDetailMessage');
                        message.textContent = data.message || '';
                        message.hidden = !data.message;

                        document.getElementById('sstDetailRequests').textContent = numberFormat.format(requests);
                        document.getElementById('sstDetailErrors').textContent = numberFormat.format(errors);
                        document.getElementById('sstDetailRate').textContent = requests ? (errors / requests * 100).toFixed(1) + '%' : '—';
                        document.getElementById('sstDetailAvg').textContent = routeRequests ? Math.round(weightedMs / routeRequests) + ' ms' : '—';

                        const chart = document.getElementById('sstDetailChart');
                        chart.classList.remove('is-loading');
                        renderChart(chart, data.hourly);

                        const signalBox = document.getElementById('sstDetailSignal');
                        if (data.signal.calls > 0 || data.signal.last_failure) {
                            let text = fill(i18n.signals, {failures: data.signal.failures, calls: data.signal.calls});
                            if (data.signal.last_failure) {
                                text += ' ' + fill(i18n.lastFailure, {reason: data.signal.last_failure.reason, time: data.signal.last_failure.at});
                            }
                            signalBox.textContent = text;
                            signalBox.dataset.failed = data.signal.failures > 0 ? '1' : '0';
                            signalBox.hidden = false;
                        }

                        const routes = document.getElementById('sstDetailRoutes');
                        if (!data.routes.length) {
                            routes.appendChild(emptyRow(6, i18n.noTraffic));
                        }
                        data.routes.forEach(function (route) {
                            const tr = row([route.route, numberFormat.format(route.requests), numberFormat.format(route.errors), route.error_rate + '%', route.slow, route.avg_ms], 1);
                            tr.children[0].className = 'sst-route';
                            if (route.errors > 0) {
                                tr.classList.add('has-errors');
                            }
                            routes.appendChild(tr);
                        });

                        const checks = document.getElementById('sstDetailChecks');
                        if (!data.checks.length) {
                            checks.appendChild(emptyRow(5, i18n.noData));
                        }
                        data.checks.forEach(function (check) {
                            const tr = row([check.checked_at, check.probe, '', check.response_ms, check.message]);
                            tr.children[3].className = 'sst-num';
                            const pill = document.createElement('span');
                            pill.className = 'sst-pill';
                            setPill(pill, check.status === 'skipped' ? 'not_configured' : check.status, check.status_label);
                            tr.children[2].appendChild(pill);
                            checks.appendChild(tr);
                        });

                        renderIncidents(document.getElementById('sstDetailIncidents'), data.incidents);
                    })
                    .fail(function () {
                        document.getElementById('sstDetailChart').classList.remove('is-loading');
                        const message = document.getElementById('sstDetailMessage');
                        message.textContent = i18n.loadFailed;
                        message.hidden = false;
                    });
            }

            $(document).on('click', '.sst-component-name', function () {
                openDetail(this.dataset.component);
            });
            $('#sstDetailModal').on('hide.bs.modal', hideTooltip);

            markRange();
            load();
        })(jQuery);
    </script>
@endpush
