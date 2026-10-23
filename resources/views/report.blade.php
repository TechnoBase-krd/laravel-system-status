{{--
    Monthly uptime report: a self-contained page (also rendered to PDF with dompdf), so it uses
    plain HTML and inline styles only. $report comes from GetStatusReportAction::execute().
--}}
@php
    $locale = str_replace('_', '-', app()->getLocale());
    $rtl = in_array(strtok($locale, '-'), (array) config('system-status.standalone.rtl_locales', ['ar', 'ku', 'ckb', 'fa', 'he', 'ur']), true);
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 3), '0'), '.').'%';
    $links = $links ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@lang('system-status::status.status_report_title') · {{ $report['month_label'] }} · {{ $report['app'] }}</title>
    <style>
        body { margin: 0; padding: 32px 16px; background: #f5f6f8; color: #111827; font-family: "DejaVu Sans", system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", Tahoma, sans-serif; font-size: 14px; }
        .report { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgb(16 24 40 / .08); }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .meta { margin: 0 0 20px; color: #6b7280; }
        .summary { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .summary div { flex: 1 1 180px; padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 10px; }
        .summary span { display: block; font-size: 12px; color: #6b7280; }
        .summary strong { font-size: 20px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: start; white-space: nowrap; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; background: #f9fafb; }
        td.num, th.num { text-align: end; font-variant-numeric: tabular-nums; }
        tr.group td { padding-top: 16px; font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb; }
        .met { color: #15803d; font-weight: 600; }
        .missed { color: #b91c1c; font-weight: 700; }
        .nav { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .nav a, .nav button { padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 999px; background: #fff; color: #374151; text-decoration: none; font: inherit; cursor: pointer; }
        .nav .spacer { flex: 1; }
        @media print { body { background: #fff; padding: 0; } .report { box-shadow: none; } .nav { display: none; } }
    </style>
</head>
<body>
<main class="report">
    @if($links)
        <nav class="nav">
            <a href="{{ $links['previous'] }}">{{ $rtl ? '→' : '←' }} @lang('system-status::status.status_report_previous')</a>
            @if($links['next'])
                <a href="{{ $links['next'] }}">@lang('system-status::status.status_report_next') {{ $rtl ? '←' : '→' }}</a>
            @endif
            <span class="spacer"></span>
            <a href="{{ $links['csv'] }}">CSV</a>
            @if($links['pdf'])
                <a href="{{ $links['pdf'] }}">PDF</a>
            @endif
            <button type="button" onclick="window.print()">@lang('system-status::status.status_report_print')</button>
            <a href="{{ $links['back'] }}">@lang('system-status::status.back')</a>
        </nav>
    @endif

    <h1>@lang('system-status::status.status_report_title') — {{ $report['month_label'] }}</h1>
    <p class="meta">{{ $report['app'] }} · @lang('system-status::status.status_report_generated', ['time' => $report['generated_at']])</p>

    <div class="summary">
        <div>
            <span>@lang('system-status::status.status_report_average')</span>
            <strong>{{ $percent($report['average_uptime']) }}</strong>
        </div>
        <div>
            <span>@lang('system-status::status.status_report_missed_label')</span>
            <strong class="{{ $report['missed'] ? 'missed' : 'met' }}">{{ $report['missed'] }}</strong>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>@lang('system-status::status.status_form_component')</th>
                <th class="num">@lang('system-status::status.status_report_uptime')</th>
                <th class="num">@lang('system-status::status.status_report_target')</th>
                <th>@lang('system-status::status.status_report_sla')</th>
                <th class="num">@lang('system-status::status.status_report_outage_min')</th>
                <th class="num">@lang('system-status::status.status_report_maintenance_min')</th>
                <th class="num">@lang('system-status::status.status_incidents')</th>
            </tr>
            </thead>
            <tbody>
            @foreach(collect($report['components'])->groupBy('group') as $components)
                <tr class="group"><td colspan="7">{{ $components->first()['group_label'] }}</td></tr>
                @foreach($components as $c)
                    <tr>
                        <td>{{ $c['label'] }}</td>
                        <td class="num">{{ $percent($c['uptime']) }}</td>
                        <td class="num">{{ $c['target'] === null ? '—' : $percent($c['target']) }}</td>
                        <td>
                            @if($c['met'] === true)
                                <span class="met">@lang('system-status::status.status_report_met')</span>
                            @elseif($c['met'] === false)
                                <span class="missed">@lang('system-status::status.status_report_missed')</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num">{{ number_format($c['outage_minutes']) }}</td>
                        <td class="num">{{ number_format($c['maintenance_minutes']) }}</td>
                        <td class="num">{{ number_format($c['incidents']) }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
