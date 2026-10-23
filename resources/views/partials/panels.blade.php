{{--
    Slide-over panels for the write actions (native <dialog>, slides in from the inline end, so
    from the left in RTL) and the per-component menu. Esc or the backdrop closes a panel;
    Ctrl / ⌘ + Enter submits it.
--}}
@php
    $label = 'tw:mb-1.5 tw:block tw:text-sm tw:font-semibold tw:text-gray-800';
    $hint = 'tw:mt-1 tw:text-xs tw:text-gray-500';
    $input = 'tw:block tw:w-full tw:rounded-lg tw:border tw:border-gray-300 tw:bg-white tw:px-3 tw:py-2.5 tw:text-sm tw:text-gray-900 tw:shadow-xs tw:outline-none tw:transition tw:placeholder:text-gray-400 tw:focus:border-(--sst-accent) tw:focus:ring-4 tw:focus:ring-(--sst-accent)/15';
    $segGroup = 'tw:grid tw:gap-2';
    $seg = 'tw:relative tw:flex tw:cursor-pointer tw:items-center tw:justify-center tw:gap-2 tw:rounded-lg tw:border tw:border-gray-300 tw:bg-white tw:px-3 tw:py-2 tw:text-sm tw:font-medium tw:text-gray-700 tw:transition tw:select-none tw:hover:bg-gray-50 tw:has-focus-visible:ring-4 tw:has-focus-visible:ring-(--sst-accent)/20';
    $chip = 'tw:inline-flex tw:cursor-pointer tw:items-center tw:rounded-full tw:border tw:border-gray-300 tw:bg-white tw:px-3 tw:py-1.5 tw:text-sm tw:font-medium tw:text-gray-700 tw:transition tw:select-none tw:hover:bg-gray-50 tw:has-checked:border-(--sst-accent) tw:has-checked:bg-(--sst-accent) tw:has-checked:text-white tw:has-focus-visible:ring-4 tw:has-focus-visible:ring-(--sst-accent)/20';
    $primary = 'sst-submit tw:inline-flex tw:items-center tw:gap-2 tw:rounded-lg tw:bg-(--sst-accent) tw:px-4 tw:py-2.5 tw:text-sm tw:font-semibold tw:text-white tw:shadow-sm tw:transition tw:hover:brightness-110 tw:disabled:cursor-wait tw:disabled:opacity-60';
    $secondary = 'tw:inline-flex tw:items-center tw:rounded-lg tw:px-4 tw:py-2.5 tw:text-sm tw:font-medium tw:text-gray-700 tw:transition tw:hover:bg-gray-200';
    $close = 'tw:grid tw:size-9 tw:shrink-0 tw:place-items-center tw:rounded-full tw:text-gray-500 tw:transition tw:hover:bg-gray-100 tw:hover:text-gray-900';
    $error = 'sst-form-error tw:rounded-lg tw:border tw:border-red-200 tw:bg-red-50 tw:px-4 tw:py-3 tw:text-sm tw:text-red-800';
    $states = ['investigating' => 'tw:bg-amber-500', 'identified' => 'tw:bg-orange-500', 'monitoring' => 'tw:bg-blue-500', 'resolved' => 'tw:bg-green-600'];
    $stateChecked = [
        'investigating' => 'tw:has-checked:border-amber-400 tw:has-checked:bg-amber-50 tw:has-checked:text-amber-900',
        'identified' => 'tw:has-checked:border-orange-400 tw:has-checked:bg-orange-50 tw:has-checked:text-orange-900',
        'monitoring' => 'tw:has-checked:border-blue-400 tw:has-checked:bg-blue-50 tw:has-checked:text-blue-900',
        'resolved' => 'tw:has-checked:border-green-500 tw:has-checked:bg-green-50 tw:has-checked:text-green-900',
    ];
    $durations = [15, 30, 60, 120, 240];
@endphp

{{-- ---------- Report incident ---------- --}}
<dialog id="sstIncidentSheet" class="sst sst-ui sst-sheet" aria-labelledby="sstIncidentPanelTitle">
    <form class="tw:flex tw:h-full tw:flex-col" id="sstIncidentForm" novalidate>
        <header class="tw:flex tw:items-start tw:gap-3 tw:border-b tw:border-gray-200 tw:px-6 tw:py-5">
            <span class="tw:grid tw:size-10 tw:shrink-0 tw:place-items-center tw:rounded-xl tw:bg-red-50 tw:text-red-600"><i class="fas fa-bullhorn" aria-hidden="true"></i></span>
            <div class="tw:min-w-0 tw:flex-1">
                <h2 class="tw:text-lg tw:font-bold tw:text-gray-900" id="sstIncidentPanelTitle">@lang('system-status::status.status_report_incident')</h2>
                <p class="tw:mt-0.5 tw:text-sm tw:text-gray-500">@lang('system-status::status.status_incident_hint')</p>
            </div>
            <button type="button" class="{{ $close }}" data-close-dialog aria-label="{{ __('system-status::status.close') }}"><i class="fas fa-times" aria-hidden="true"></i></button>
        </header>

        <div class="tw:flex-1 tw:space-y-5 tw:overflow-y-auto tw:px-6 tw:py-5">
            <div class="{{ $error }}" hidden></div>

            <div>
                <label class="{{ $label }}" for="sstIncidentComponent">@lang('system-status::status.status_form_component')</label>
                <select class="{{ $input }}" id="sstIncidentComponent" name="component" required data-autofocus>
                    @foreach(($componentOptions ?? []) as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach($group['components'] as $key => $componentLabel)
                                <option value="{{ $key }}">{{ $componentLabel }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <fieldset>
                <legend class="{{ $label }}">@lang('system-status::status.status_form_severity')</legend>
                <div class="{{ $segGroup }} tw:grid-cols-2">
                    <label class="{{ $seg }} tw:has-checked:border-amber-400 tw:has-checked:bg-amber-50 tw:has-checked:text-amber-900">
                        <input type="radio" class="tw:sr-only" name="severity" value="degraded" checked>
                        <span class="tw:size-2.5 tw:rounded-full tw:bg-amber-500" aria-hidden="true"></span>
                        @lang('system-status::status.status_degraded')
                    </label>
                    <label class="{{ $seg }} tw:has-checked:border-red-400 tw:has-checked:bg-red-50 tw:has-checked:text-red-900">
                        <input type="radio" class="tw:sr-only" name="severity" value="major_outage">
                        <span class="tw:size-2.5 tw:rounded-full tw:bg-red-600" aria-hidden="true"></span>
                        @lang('system-status::status.status_major_outage')
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend class="{{ $label }}">@lang('system-status::status.status_form_state')</legend>
                <div class="{{ $segGroup }} tw:grid-cols-3">
                    @foreach(['investigating', 'identified', 'monitoring'] as $state)
                        <label class="{{ $seg }} {{ $stateChecked[$state] }}">
                            <input type="radio" class="tw:sr-only" name="state" value="{{ $state }}" @checked($loop->first)>
                            <span class="tw:size-2 tw:rounded-full {{ $states[$state] }}" aria-hidden="true"></span>
                            @lang('system-status::status.status_state_'.$state)
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label class="{{ $label }}" for="sstIncidentTitle">@lang('system-status::status.status_form_title')</label>
                <input type="text" class="{{ $input }}" id="sstIncidentTitle" name="title" maxlength="200" required
                       placeholder="{{ __('system-status::status.status_incident_title_placeholder') }}">
            </div>

            <div>
                <label class="{{ $label }}" for="sstIncidentMessage">@lang('system-status::status.status_form_message')</label>
                <textarea class="{{ $input }} tw:min-h-28" id="sstIncidentMessage" name="message" rows="4" maxlength="500"></textarea>
                <p class="{{ $hint }}">@lang('system-status::status.status_incident_message_hint')</p>
            </div>
        </div>

        <footer class="tw:flex tw:items-center tw:gap-3 tw:border-t tw:border-gray-200 tw:bg-gray-50 tw:px-6 tw:py-4">
            <span class="tw:hidden tw:text-xs tw:text-gray-500 tw:sm:inline">@lang('system-status::status.status_shortcut_submit')</span>
            <button type="button" class="{{ $secondary }} tw:ms-auto" data-close-dialog>@lang('system-status::status.status_cancel')</button>
            <button type="submit" class="{{ $primary }}"><i class="fas fa-paper-plane" aria-hidden="true"></i> @lang('system-status::status.status_form_post')</button>
        </footer>
    </form>
</dialog>

{{-- ---------- Schedule maintenance ---------- --}}
<dialog id="sstMaintenanceSheet" class="sst sst-ui sst-sheet" aria-labelledby="sstMaintenancePanelTitle">
    <form class="tw:flex tw:h-full tw:flex-col" id="sstMaintenanceForm" novalidate>
        <header class="tw:flex tw:items-start tw:gap-3 tw:border-b tw:border-gray-200 tw:px-6 tw:py-5">
            <span class="tw:grid tw:size-10 tw:shrink-0 tw:place-items-center tw:rounded-xl tw:bg-blue-50 tw:text-blue-600"><i class="fas fa-tools" aria-hidden="true"></i></span>
            <div class="tw:min-w-0 tw:flex-1">
                <h2 class="tw:text-lg tw:font-bold tw:text-gray-900" id="sstMaintenancePanelTitle">@lang('system-status::status.status_schedule_maintenance')</h2>
                <p class="tw:mt-0.5 tw:text-sm tw:text-gray-500">@lang('system-status::status.status_maintenance_hint')</p>
            </div>
            <button type="button" class="{{ $close }}" data-close-dialog aria-label="{{ __('system-status::status.close') }}"><i class="fas fa-times" aria-hidden="true"></i></button>
        </header>

        <div class="tw:flex-1 tw:space-y-5 tw:overflow-y-auto tw:px-6 tw:py-5">
            <div class="{{ $error }}" hidden></div>

            <div>
                <label class="{{ $label }}" for="sstMaintenanceTitle">@lang('system-status::status.status_form_title')</label>
                <input type="text" class="{{ $input }}" id="sstMaintenanceTitle" name="title" maxlength="200" required data-autofocus
                       value="{{ __('system-status::status.status_maintenance_default_title') }}">
            </div>

            <fieldset>
                <legend class="{{ $label }}">@lang('system-status::status.status_form_when')</legend>
                <div class="tw:flex tw:flex-wrap tw:gap-2">
                    <label class="{{ $chip }}"><input type="radio" class="tw:sr-only" name="start_mode" value="now" checked>@lang('system-status::status.status_starts_now')</label>
                    <label class="{{ $chip }}"><input type="radio" class="tw:sr-only" name="start_mode" value="later">@lang('system-status::status.status_starts_later')</label>
                </div>
                <input type="datetime-local" class="{{ $input }} tw:mt-2" name="starts_at_local" hidden
                       aria-label="{{ __('system-status::status.status_form_starts_at') }}">
            </fieldset>

            <fieldset>
                <legend class="{{ $label }}">@lang('system-status::status.status_form_duration')</legend>
                <div class="tw:flex tw:flex-wrap tw:gap-2">
                    @foreach($durations as $minutes)
                        <label class="{{ $chip }}">
                            <input type="radio" class="tw:sr-only" name="duration" value="{{ $minutes }}" @checked($minutes === 30)>
                            {{ $minutes < 60 ? __('system-status::status.status_duration_minutes', ['minutes' => $minutes]) : __('system-status::status.status_duration_hours', ['hours' => $minutes / 60]) }}
                        </label>
                    @endforeach
                    <label class="{{ $chip }}"><input type="radio" class="tw:sr-only" name="duration" value="custom">@lang('system-status::status.status_duration_custom')</label>
                </div>
                <input type="datetime-local" class="{{ $input }} tw:mt-2" name="ends_at_local" hidden
                       aria-label="{{ __('system-status::status.status_form_ends_at') }}">
                <p class="tw:mt-2 tw:flex tw:items-center tw:gap-2 tw:rounded-lg tw:bg-blue-50 tw:px-3 tw:py-2 tw:text-sm tw:text-blue-900">
                    <i class="fas fa-clock" aria-hidden="true"></i><span class="sst-window-preview"></span>
                </p>
            </fieldset>

            <fieldset aria-labelledby="sstMaintenanceComponentsLabel">
                <div class="tw:mb-1.5 tw:flex tw:items-center tw:gap-2">
                    <span class="tw:text-sm tw:font-semibold tw:text-gray-800" id="sstMaintenanceComponentsLabel">@lang('system-status::status.status_form_components')</span>
                    <span class="sst-selected-count tw:ms-auto tw:rounded-full tw:bg-gray-100 tw:px-2 tw:py-0.5 tw:text-xs tw:font-semibold tw:text-gray-600"></span>
                </div>
                <div class="tw:relative tw:mb-2">
                    <i class="fas fa-search tw:pointer-events-none tw:absolute tw:inset-y-0 tw:start-3 tw:my-auto tw:h-fit tw:text-xs tw:text-gray-400" aria-hidden="true"></i>
                    <input type="search" class="{{ $input }} tw:ps-8 sst-picker-search" placeholder="{{ __('system-status::status.status_search_placeholder') }}"
                           aria-label="{{ __('system-status::status.status_search_placeholder') }}">
                </div>
                <div class="tw:max-h-72 tw:space-y-3 tw:overflow-y-auto tw:rounded-lg tw:border tw:border-gray-200 tw:p-3">
                    @foreach(($componentOptions ?? []) as $groupKey => $group)
                        <div class="sst-picker-group" data-group="{{ $groupKey }}">
                            <div class="tw:mb-1.5 tw:flex tw:items-center tw:gap-2">
                                <strong class="tw:text-[0.7rem] tw:font-bold tw:uppercase tw:tracking-wide tw:text-gray-500">{{ $group['label'] }}</strong>
                                <button type="button" class="sst-picker-all tw:ms-auto tw:rounded tw:px-1.5 tw:text-xs tw:font-semibold tw:text-(--sst-accent) tw:hover:underline">@lang('system-status::status.status_select_all')</button>
                            </div>
                            <div class="tw:flex tw:flex-wrap tw:gap-1.5">
                                @foreach($group['components'] as $key => $componentLabel)
                                    <label class="sst-picker-item {{ $chip }} tw:py-1 tw:text-xs" data-search="{{ mb_strtolower($componentLabel.' '.$key) }}">
                                        <input type="checkbox" class="tw:sr-only" name="components[]" value="{{ $key }}">{{ $componentLabel }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label class="{{ $label }}" for="sstMaintenanceMessage">@lang('system-status::status.status_form_message')</label>
                <textarea class="{{ $input }} tw:min-h-24" id="sstMaintenanceMessage" name="message" rows="3" maxlength="2000"></textarea>
            </div>
        </div>

        <footer class="tw:flex tw:items-center tw:gap-3 tw:border-t tw:border-gray-200 tw:bg-gray-50 tw:px-6 tw:py-4">
            <span class="tw:hidden tw:text-xs tw:text-gray-500 tw:sm:inline">@lang('system-status::status.status_shortcut_submit')</span>
            <button type="button" class="{{ $secondary }} tw:ms-auto" data-close-dialog>@lang('system-status::status.status_cancel')</button>
            <button type="submit" class="{{ $primary }}"><i class="fas fa-calendar-check" aria-hidden="true"></i> @lang('system-status::status.status_form_schedule')</button>
        </footer>
    </form>
</dialog>

{{-- ---------- Timeline update ---------- --}}
<dialog id="sstUpdateSheet" class="sst sst-ui sst-sheet" aria-labelledby="sstUpdatePanelTitle">
    <form class="tw:flex tw:h-full tw:flex-col" id="sstUpdateForm" novalidate>
        <input type="hidden" name="incident_id">
        <header class="tw:flex tw:items-start tw:gap-3 tw:border-b tw:border-gray-200 tw:px-6 tw:py-5">
            <span class="tw:grid tw:size-10 tw:shrink-0 tw:place-items-center tw:rounded-xl tw:bg-amber-50 tw:text-amber-600"><i class="fas fa-comment-dots" aria-hidden="true"></i></span>
            <div class="tw:min-w-0 tw:flex-1">
                <h2 class="tw:text-lg tw:font-bold tw:text-gray-900" id="sstUpdatePanelTitle">@lang('system-status::status.status_add_update')</h2>
                <p class="sst-update-for tw:mt-0.5 tw:truncate tw:text-sm tw:text-gray-500"></p>
            </div>
            <button type="button" class="{{ $close }}" data-close-dialog aria-label="{{ __('system-status::status.close') }}"><i class="fas fa-times" aria-hidden="true"></i></button>
        </header>

        <div class="tw:flex-1 tw:space-y-5 tw:overflow-y-auto tw:px-6 tw:py-5">
            <div class="{{ $error }}" hidden></div>

            {{-- Where the incident is now: a small stepper --}}
            <ol class="sst-stepper tw:flex tw:items-center tw:gap-1" aria-hidden="true">
                @foreach(array_keys($states) as $state)
                    <li class="tw:flex tw:flex-1 tw:flex-col tw:items-center tw:gap-1.5" data-step="{{ $state }}">
                        <span class="sst-step-bar tw:h-1.5 tw:w-full tw:rounded-full"></span>
                        <span class="tw:text-[0.7rem] tw:font-medium tw:text-gray-500">@lang('system-status::status.status_state_'.$state)</span>
                    </li>
                @endforeach
            </ol>

            <fieldset>
                <legend class="{{ $label }}">@lang('system-status::status.status_form_state')</legend>
                <div class="{{ $segGroup }} tw:grid-cols-2">
                    @foreach($states as $state => $dot)
                        <label class="{{ $seg }} {{ $stateChecked[$state] }}" data-state-option="{{ $state }}">
                            <input type="radio" class="tw:sr-only" name="state" value="{{ $state }}">
                            <span class="tw:size-2 tw:rounded-full {{ $dot }}" aria-hidden="true"></span>
                            @lang('system-status::status.status_state_'.$state)
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label class="{{ $label }}" for="sstUpdateMessage">@lang('system-status::status.status_message')</label>
                <textarea class="{{ $input }} tw:min-h-32" id="sstUpdateMessage" name="message" rows="5" maxlength="2000" required data-autofocus></textarea>
            </div>
        </div>

        <footer class="tw:flex tw:items-center tw:gap-3 tw:border-t tw:border-gray-200 tw:bg-gray-50 tw:px-6 tw:py-4">
            <span class="tw:hidden tw:text-xs tw:text-gray-500 tw:sm:inline">@lang('system-status::status.status_shortcut_submit')</span>
            <button type="button" class="{{ $secondary }} tw:ms-auto" data-close-dialog>@lang('system-status::status.status_cancel')</button>
            <button type="submit" class="{{ $primary }}"><i class="fas fa-paper-plane" aria-hidden="true"></i> @lang('system-status::status.status_form_post')</button>
        </footer>
    </form>
</dialog>

{{-- ---------- Postmortem ---------- --}}
<dialog id="sstPostmortemSheet" class="sst sst-ui sst-sheet sst-sheet-wide" aria-labelledby="sstPostmortemPanelTitle">
    <form class="tw:flex tw:h-full tw:flex-col" id="sstPostmortemForm" novalidate>
        <input type="hidden" name="incident_id">
        <header class="tw:flex tw:items-start tw:gap-3 tw:border-b tw:border-gray-200 tw:px-6 tw:py-5">
            <span class="tw:grid tw:size-10 tw:shrink-0 tw:place-items-center tw:rounded-xl tw:bg-green-50 tw:text-green-700"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
            <div class="tw:min-w-0 tw:flex-1">
                <h2 class="tw:text-lg tw:font-bold tw:text-gray-900" id="sstPostmortemPanelTitle">@lang('system-status::status.status_postmortem')</h2>
                <p class="tw:mt-0.5 tw:text-sm tw:text-gray-500">@lang('system-status::status.status_postmortem_hint')</p>
            </div>
            <button type="button" class="{{ $close }}" data-close-dialog aria-label="{{ __('system-status::status.close') }}"><i class="fas fa-times" aria-hidden="true"></i></button>
        </header>

        <div class="tw:flex-1 tw:space-y-4 tw:overflow-y-auto tw:px-6 tw:py-5">
            <div class="{{ $error }}" hidden></div>
            <p class="sst-update-for tw:truncate tw:text-sm tw:font-semibold tw:text-gray-700"></p>
            <textarea class="{{ $input }} tw:min-h-80 tw:font-mono tw:text-[0.85rem] tw:leading-relaxed" name="postmortem" rows="14" maxlength="10000" data-autofocus
                      placeholder="{{ __('system-status::status.status_postmortem_placeholder') }}"
                      aria-label="{{ __('system-status::status.status_postmortem') }}"></textarea>
        </div>

        <footer class="tw:flex tw:items-center tw:gap-3 tw:border-t tw:border-gray-200 tw:bg-gray-50 tw:px-6 tw:py-4">
            <span class="tw:hidden tw:text-xs tw:text-gray-500 tw:sm:inline">@lang('system-status::status.status_shortcut_submit')</span>
            <button type="button" class="{{ $secondary }} tw:ms-auto" data-close-dialog>@lang('system-status::status.status_cancel')</button>
            <button type="submit" class="{{ $primary }}"><i class="fas fa-check" aria-hidden="true"></i> @lang('system-status::status.status_form_save')</button>
        </footer>
    </form>
</dialog>

{{-- ---------- Per-component menu ---------- --}}
<div id="sstMenu" class="sst sst-ui sst-menu tw:w-60 tw:rounded-xl tw:border tw:border-gray-200 tw:bg-white tw:p-1.5 tw:shadow-xl" role="menu" hidden>
    <p class="sst-menu-title tw:truncate tw:px-3 tw:pt-1.5 tw:pb-1 tw:text-xs tw:font-semibold tw:text-gray-500"></p>
    @foreach([
        ['incident', 'fa-bullhorn', 'tw:text-red-600', 'status_report_incident'],
        ['maintenance', 'fa-tools', 'tw:text-blue-600', 'status_schedule_maintenance'],
        ['details', 'fa-chart-bar', 'tw:text-gray-500', 'status_show_details'],
    ] as [$action, $icon, $tone, $text])
        <button type="button" role="menuitem" data-menu-action="{{ $action }}"
                class="tw:flex tw:w-full tw:items-center tw:gap-3 tw:rounded-lg tw:px-3 tw:py-2 tw:text-start tw:text-sm tw:text-gray-800 tw:hover:bg-gray-100 tw:focus-visible:bg-gray-100 tw:focus-visible:outline-none">
            <i class="fas {{ $icon }} tw:w-4 {{ $tone }}" aria-hidden="true"></i>@lang('system-status::status.'.$text)
        </button>
    @endforeach
</div>
