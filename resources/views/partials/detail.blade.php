{{-- Component detail: a centered native <dialog>, opened by clicking a component name. --}}
@php
    $th = 'tw:px-3 tw:py-2 tw:text-start tw:text-[0.7rem] tw:font-bold tw:uppercase tw:tracking-wide tw:text-gray-500 tw:bg-gray-50 tw:whitespace-nowrap';
    $thNum = $th.' tw:text-end';
@endphp
<dialog id="sstDetail" class="sst sst-ui sst-dialog sst-detail" aria-labelledby="sstDetailTitle">
    <div class="tw:flex tw:max-h-[inherit] tw:flex-col">
        <header class="tw:flex tw:items-center tw:gap-3 tw:border-b tw:border-gray-200 tw:bg-gray-50 tw:px-5 tw:py-4">
            <span class="sst-dot" id="sstDetailDot" aria-hidden="true"></span>
            <h2 class="tw:min-w-0 tw:truncate tw:text-lg tw:font-bold tw:text-gray-900" id="sstDetailTitle"></h2>
            <span class="sst-pill" id="sstDetailPill"></span>
            <button type="button" class="tw:ms-auto tw:grid tw:size-9 tw:place-items-center tw:rounded-full tw:text-gray-500 tw:hover:bg-gray-200 tw:hover:text-gray-900"
                    data-close-dialog aria-label="{{ __('system-status::status.close') }}">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <div class="tw:overflow-y-auto tw:p-5">
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
                <div class="sst-kpi">
                    <span class="sst-kpi-label">@lang('system-status::status.status_p95_response')</span>
                    <strong class="sst-kpi-value" id="sstDetailP95">—</strong>
                </div>
            </div>

            <div class="tw:grid tw:gap-4 tw:lg:grid-cols-2">
                <section class="sst-panel">
                    <header class="sst-panel-head">
                        <h3 class="sst-panel-title">@lang('system-status::status.status_requests_24h')</h3>
                        <ul class="sst-legend">
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

                <section class="sst-panel">
                    <header class="sst-panel-head">
                        <h3 class="sst-panel-title">@lang('system-status::status.status_response_time_24h')</h3>
                        <ul class="sst-legend">
                            <li><span class="sst-swatch" data-level="p95"></span>p95</li>
                            <li><span class="sst-swatch" data-level="p50"></span>p50</li>
                        </ul>
                    </header>
                    <div class="sst-panel-body">
                        <div class="sst-chart sst-latency-chart" id="sstDetailLatency"></div>
                        <div class="sst-bars-legend">
                            <span>@lang('system-status::status.status_hours_ago')</span>
                            <span>@lang('system-status::status.status_now')</span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="sst-signal" id="sstDetailSignal" hidden></div>

            <section class="sst-panel">
                <header class="sst-panel-head">
                    <h3 class="sst-panel-title">@lang('system-status::status.status_top_routes')</h3>
                </header>
                <div class="tw:overflow-x-auto">
                    <table class="sst-table tw:w-full">
                        <thead>
                        <tr>
                            <th class="{{ $th }}">@lang('system-status::status.status_route')</th>
                            <th class="{{ $thNum }}">@lang('system-status::status.status_requests')</th>
                            <th class="{{ $thNum }}">@lang('system-status::status.status_errors')</th>
                            <th class="{{ $thNum }}">@lang('system-status::status.status_error_rate')</th>
                            <th class="{{ $thNum }}">@lang('system-status::status.status_slow')</th>
                            <th class="{{ $thNum }}">@lang('system-status::status.status_avg_ms')</th>
                            <th class="{{ $thNum }}">p95 ms</th>
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
                <div class="tw:overflow-x-auto">
                    <table class="sst-table tw:w-full">
                        <thead>
                        <tr>
                            <th class="{{ $th }}">@lang('system-status::status.status_checked_at')</th>
                            <th class="{{ $th }}">@lang('system-status::status.status_probe')</th>
                            <th class="{{ $th }}">@lang('system-status::status.status')</th>
                            <th class="{{ $thNum }}">@lang('system-status::status.status_response_ms')</th>
                            <th class="{{ $th }}">@lang('system-status::status.status_message')</th>
                        </tr>
                        </thead>
                        <tbody id="sstDetailChecks"></tbody>
                    </table>
                </div>
            </section>

            <section class="sst-panel tw:mb-0">
                <header class="sst-panel-head">
                    <h3 class="sst-panel-title">@lang('system-status::status.status_incidents')</h3>
                </header>
                <div class="sst-panel-body" id="sstDetailIncidents"></div>
            </section>
        </div>
    </div>
</dialog>
