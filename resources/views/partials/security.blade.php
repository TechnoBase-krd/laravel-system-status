{{-- Suspicious activity finding: a centered native <dialog>, opened from the "Suspicious activity" panel. --}}
<dialog id="sstSecurityDialog" class="sst sst-ui sst-dialog sst-detail sst-security-dialog" aria-labelledby="sstSecTitle">
    <div class="tw:flex tw:max-h-[inherit] tw:flex-col">
        <header class="sst-sec-head tw:flex tw:items-center tw:gap-3 tw:border-b tw:border-gray-200 tw:px-5 tw:py-4">
            <span class="sst-sec-badge" aria-hidden="true"><i class="fas fa-shield-alt" id="sstSecIcon"></i></span>
            <div class="tw:min-w-0">
                <h2 class="tw:truncate tw:text-lg tw:font-bold tw:text-gray-900" id="sstSecTitle"></h2>
                <p class="tw:truncate tw:text-xs tw:text-gray-500" id="sstSecLimit"></p>
            </div>
            <span class="sst-sec-state" id="sstSecState"></span>
            <button type="button" class="tw:ms-auto tw:grid tw:size-9 tw:shrink-0 tw:place-items-center tw:rounded-full tw:text-gray-500 tw:hover:bg-gray-200 tw:hover:text-gray-900"
                    data-close-dialog aria-label="{{ __('system-status::status.close') }}">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <div class="sst-detail-body tw:overflow-y-auto tw:p-5">
            <p class="sst-sec-text" id="sstSecText"></p>

            <div class="sst-kpis sst-kpis-compact">
                <div class="sst-kpi" data-tone="danger">
                    <span class="sst-kpi-label">@lang('system-status::status.status_security_label_hits')</span>
                    <strong class="sst-kpi-value" id="sstSecHits">—</strong>
                </div>
                <div class="sst-kpi">
                    <span class="sst-kpi-label">@lang('system-status::status.status_security_label_first_seen')</span>
                    <strong class="sst-kpi-value sst-kpi-time" id="sstSecFirst">—</strong>
                </div>
                <div class="sst-kpi">
                    <span class="sst-kpi-label">@lang('system-status::status.status_security_label_last_seen')</span>
                    <strong class="sst-kpi-value sst-kpi-time" id="sstSecLast">—</strong>
                </div>
                <div class="sst-kpi" data-tone="warning">
                    <span class="sst-kpi-label">@lang('system-status::status.status_security_label_duration')</span>
                    <strong class="sst-kpi-value sst-kpi-time" id="sstSecDuration">—</strong>
                </div>
            </div>

            <section class="sst-panel">
                <header class="sst-panel-head">
                    <h3 class="sst-panel-title"><i class="fas fa-fingerprint" aria-hidden="true"></i> @lang('system-status::status.status_security_label_details')</h3>
                </header>
                <dl class="sst-sec-facts" id="sstSecFacts"></dl>
            </section>

            <section class="sst-panel sst-sec-advice">
                <header class="sst-panel-head">
                    <h3 class="sst-panel-title"><i class="fas fa-lightbulb" aria-hidden="true"></i> @lang('system-status::status.status_security_advice_title')</h3>
                </header>
                <p class="sst-panel-body tw:m-0" id="sstSecAdvice"></p>
            </section>

            <section class="sst-panel tw:mb-0">
                <header class="sst-panel-head">
                    <h3 class="sst-panel-title"><i class="fas fa-network-wired" aria-hidden="true"></i> @lang('system-status::status.status_security_same_ip')</h3>
                </header>
                <div class="sst-panel-body tw:py-1">
                    <ul class="sst-deploys" id="sstSecSameIp"></ul>
                </div>
            </section>
        </div>
    </div>
</dialog>
