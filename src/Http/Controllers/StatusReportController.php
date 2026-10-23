<?php

namespace Technobase\SystemStatus\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Technobase\SystemStatus\Actions\GetStatusReportAction;

/**
 * Monthly uptime report as a printable page, CSV or (with barryvdh/laravel-dompdf) PDF.
 * Access: AuthorizeSystemStatus.
 */
class StatusReportController extends Controller
{
    public function show(string $month, string $format, GetStatusReportAction $action): Response
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01');
        abort_unless($start && $start->format('Y-m') === $month, 404);

        $report = $action->execute($month);
        $filename = 'uptime-'.$month;

        return match ($format) {
            'csv' => response($action->csv($report), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
            ]),
            'pdf' => GetStatusReportAction::pdfAvailable()
                ? response($action->pdf($report), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
                ])
                : abort(404),
            default => response()->view('system-status::report', [
                'report' => $report,
                'links' => [
                    'previous' => route('system_status.report', ['month' => $start->copy()->subMonth()->format('Y-m'), 'format' => 'html']),
                    'next' => $start->copy()->addMonth()->lte(now()) ? route('system_status.report', ['month' => $start->copy()->addMonth()->format('Y-m'), 'format' => 'html']) : null,
                    'csv' => route('system_status.report', ['month' => $month, 'format' => 'csv']),
                    'pdf' => GetStatusReportAction::pdfAvailable() ? route('system_status.report', ['month' => $month, 'format' => 'pdf']) : null,
                    'back' => route('system_status.index'),
                ],
            ]),
        };
    }
}
