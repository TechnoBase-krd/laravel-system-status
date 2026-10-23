{{--
    Minimal document for the PDF version of the monthly report (dompdf). dompdf has no CSS
    variables, flex or grid, so the page's classes get simple equivalents here.
--}}
@php
    $accent = config('system-status.reports.accent') ?: '#6F2E8F';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        body { margin: 0; padding: 24px; color: #111827; font-family: "DejaVu Sans", sans-serif; font-size: 11px; line-height: 1.45; }
        .sst-hero { padding: 18px 22px; margin-bottom: 14px; border-radius: 10px; background: {{ $accent }}; color: #fff; }
        .sst-hero-icon, .sst-hero-actions { display: none; }
        .sst-hero-eyebrow { font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .sst-hero-title { margin: 4px 0 2px; font-size: 22px; }
        .sst-hero-meta { margin: 0; font-size: 10px; }
        .sst-kpis { margin-bottom: 12px; }
        .sst-kpi { display: inline-block; width: 22%; margin-right: 1.5%; padding: 10px 12px; border: 1px solid #e5e7eb; border-left: 4px solid {{ $accent }}; border-radius: 8px; vertical-align: top; }
        .sst-kpi[data-tone="ok"] { border-left-color: #16a34a; } .sst-kpi[data-tone="warn"] { border-left-color: #f59e0b; } .sst-kpi[data-tone="bad"] { border-left-color: #dc2626; }
        .sst-kpi-label { display: block; font-size: 9px; font-weight: bold; color: #6b7280; }
        .sst-kpi-value { display: block; font-size: 17px; font-weight: bold; }
        .sst-kpi-sub { display: block; font-size: 9px; color: #6b7280; }
        .sst-panel { margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 8px; }
        .sst-panel-head { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; }
        .sst-panel-title { margin: 0; font-size: 12px; }
        .sst-panel-body { padding: 10px 12px; }
        .sst-legend { margin: 0; padding: 0; list-style: none; font-size: 9px; color: #6b7280; }
        .sst-legend li { display: inline-block; margin-right: 10px; }
        .sst-swatch { display: inline-block; width: 8px; height: 8px; margin-right: 3px; border-radius: 2px; }
        .sst-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .sst-table th { padding: 6px 8px; background: #f9fafb; color: #6b7280; font-size: 8px; text-transform: uppercase; text-align: left; }
        .sst-table td { padding: 6px 8px; border-top: 1px solid #f1f2f5; }
        .sst-pill { padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: bold; background: #f3f4f6; color: #374151; }
        .sst-pill[data-status="operational"] { background: #dcfce7; color: #166534; }
        .sst-pill[data-status="major_outage"] { background: #fee2e2; color: #991b1b; }
        .sst-muted { color: #6b7280; }
    </style>
    @stack('report-styles')
</head>
<body>
    @yield('content')
</body>
</html>
