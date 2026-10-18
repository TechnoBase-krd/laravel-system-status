@extends(config('system-status.layout', 'layouts.app'))

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
         data-component-url="{{ route('system_status.component', ['component' => '__component__']) }}">

        {{-- Overall status --}}
        <section class="sst-hero" id="sstHero" data-level="loading" aria-live="polite">
            <div class="sst-hero-main">
                <span class="sst-hero-icon" aria-hidden="true"><i id="sstHeroIcon" class="fas fa-circle-notch"></i></span>
                <div class="sst-hero-text">
                    <span class="sst-hero-eyebrow">@lang('system-status::status.system_status')</span>
                    <h2 class="sst-hero-title" id="sstHeroTitle">@lang('system-status::status.status_loading')</h2>
                    <p class="sst-hero-meta" id="sstHeroMeta">@lang('system-status::status.system_status_description', ['app' => config('app.name')])</p>
                </div>
            </div>
            <div class="sst-hero-actions">
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
                <li><span class="sst-swatch" data-level="none"></span>@lang('system-status::status.status_no_data')</li>
            </ul>
        </div>

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

        <template id="sstIncidentTemplate">
            <article class="sst-incident">
                <span class="sst-incident-marker" aria-hidden="true"></span>
                <div class="sst-incident-body">
                    <div class="sst-incident-head">
                        <strong class="sst-incident-component"></strong>
                        <span class="sst-pill sst-incident-severity"></span>
                        <span class="sst-incident-state"></span>
                    </div>
                    <p class="sst-incident-message"></p>
                    <small class="sst-incident-time"></small>
                </div>
            </article>
        </template>
    </div>
@endsection

@push(config('system-status.stacks.modals', 'modals'))
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
                return component.configured && component.status !== 'operational';
            }

            function worstStatus(components) {
                const order = {operational: 0, degraded: 1, major_outage: 2};
                return components.reduce(function (worst, c) {
                    return c.configured && order[c.status] > order[worst] ? c.status : worst;
                }, 'operational');
            }

            function statusLabel(status) {
                return {operational: i18n.operational, degraded: i18n.degraded, major_outage: i18n.majorOutage}[status] || status;
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

            function renderIncidents(container, incidents) {
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

                incidents.forEach(function (incident) {
                    const node = template.content.firstElementChild.cloneNode(true);
                    node.dataset.ongoing = incident.ongoing ? '1' : '0';
                    node.dataset.status = incident.severity;
                    setText(node, '.sst-incident-component', incident.component_label);
                    setPill(node.querySelector('.sst-incident-severity'), incident.severity, incident.severity_label);
                    setText(node, '.sst-incident-state', incident.ongoing ? i18n.ongoing : i18n.resolved);
                    const message = setText(node, '.sst-incident-message', incident.message || '');
                    message.hidden = !incident.message;
                    setText(node, '.sst-incident-time',
                        incident.started_at + ' → ' + (incident.resolved_at || '…') + ' · ' + fill(i18n.duration, {minutes: incident.duration_minutes}));
                    fragment.appendChild(node);
                });

                container.replaceChildren(fragment);
            }

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
                        renderIncidents(document.getElementById('sstIncidents'), state.data.incidents);
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
