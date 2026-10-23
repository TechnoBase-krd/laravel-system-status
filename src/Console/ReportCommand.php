<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Technobase\SystemStatus\Actions\GetStatusReportAction;

class ReportCommand extends Command
{
    protected $signature = 'status:report
                            {--month= : YYYY-MM (default: this month)}
                            {--previous : Report on last month}
                            {--format=table : table, csv, html or pdf}
                            {--output= : Write the report to this file}
                            {--mail=* : Email the report to these addresses}
                            {--send : Email the report to system-status.reports.mail_to}';

    protected $description = 'Monthly uptime per component against its SLA target, as a table, CSV, HTML or PDF.';

    public function handle(GetStatusReportAction $action): int
    {
        $month = $this->option('previous') ? now()->subMonthNoOverflow()->format('Y-m') : ($this->option('month') ?: now()->format('Y-m'));

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Use --month=YYYY-MM.');

            return self::FAILURE;
        }

        $format = (string) $this->option('format');
        $recipients = array_values(array_filter([
            ...(array) $this->option('mail'),
            ...($this->option('send') ? (array) config('system-status.reports.mail_to', []) : []),
        ]));

        if ($format === 'table' && $recipients !== []) {
            $format = (string) config('system-status.reports.format', 'csv');
        }

        if ($format === 'pdf' && ! GetStatusReportAction::pdfAvailable()) {
            $this->error('PDF needs barryvdh/laravel-dompdf (composer require barryvdh/laravel-dompdf).');

            return self::FAILURE;
        }

        $report = $action->execute($month);

        if ($format === 'table') {
            $this->table(
                ['Group', 'Component', 'Uptime', 'SLA', 'Outage min', 'Maintenance min', 'Incidents'],
                array_map(fn ($c) => [
                    $c['group_label'],
                    $c['label'],
                    $c['uptime'] === null ? '—' : $c['uptime'].'%',
                    $c['target'] === null ? '—' : $c['target'].'% '.($c['met'] === false ? '✗' : '✓'),
                    $c['outage_minutes'],
                    $c['maintenance_minutes'],
                    $c['incidents'],
                ], $report['components']),
            );
            $this->line('Average uptime: '.($report['average_uptime'] ?? '—').'%. Components below SLA: '.$report['missed'].'.');

            return self::SUCCESS;
        }

        $content = match ($format) {
            'csv' => $action->csv($report),
            'html' => $action->html($report),
            'pdf' => $action->pdf($report),
            default => null,
        };

        if ($content === null) {
            $this->error('Unknown format. Use table, csv, html or pdf.');

            return self::FAILURE;
        }

        if ($this->option('output')) {
            file_put_contents($this->option('output'), $content);
            $this->info('Written to '.$this->option('output'));
        } elseif ($recipients === []) {
            $this->output->write($content);
        }

        if ($recipients !== []) {
            $filename = "uptime-{$month}.{$format}";
            $subject = trim(config('system-status.alerts.prefix', '').' Uptime report '.$report['month_label']);
            $body = "Uptime report for {$report['month_label']}.\nAverage uptime: ".($report['average_uptime'] ?? '—')."%.\nComponents below SLA: {$report['missed']}.";

            Mail::raw($body, fn ($mail) => $mail->to($recipients)->subject($subject)->attachData($content, $filename, [
                'mime' => ['csv' => 'text/csv', 'html' => 'text/html', 'pdf' => 'application/pdf'][$format],
            ]));
            $this->info('Sent to '.implode(', ', $recipients));
        }

        return self::SUCCESS;
    }
}
