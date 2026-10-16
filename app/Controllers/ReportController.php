<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Clock, Http, View};
use App\Services\{AnalyticsRange, Audit, Auth, CsvWriter, ReportBuilder};

/** Aggregate reports. Router enforces `exports.aggregate` on every route; the type is whitelisted again here. */
final class ReportController
{
    private function type(): string
    {
        $type = preg_match('#^/reports/([a-z_]+)(?:/(?:csv|print))?$#', Http::path(), $m) ? $m[1] : '';
        return ReportBuilder::valid($type) ? $type : Http::abort(404);
    }

    /** Exports must never silently change the period: invalid input is rejected instead of falling back. */
    private function strictRange(): AnalyticsRange
    {
        $r = AnalyticsRange::fromQuery($_GET);
        if ($r->errors) {
            Http::abort(422, 'The report could not be generated: ' . implode(' ', array_map(static fn ($e) => preg_replace('/ Showing .*$/', '', $e), $r->errors)));
        }
        return $r;
    }

    private function meta(array $report, AnalyticsRange $r): array
    {
        return ['Report' => $report['title'], 'Reporting period' => $r->label() . ' (' . $r->days() . ' day' . ($r->days() === 1 ? '' : 's') . ', inclusive, clinic local dates)',
            'Timezone' => Clock::configuredName(), 'Generated' => Clock::now()->format('Y-m-d H:i')];
    }

    private static function rowCount(array $report): int
    {
        return array_sum(array_map(static fn ($s) => count($s['rows']), $report['sections']));
    }

    public function index(): void
    {
        $r = AnalyticsRange::fromQuery($_GET);
        View::render('reports/index', ['title' => 'Reports', 'range' => $r, 'errors' => $r->errors, 'qs' => http_build_query($r->query()), 'types' => ReportBuilder::TYPES]);
    }

    public function show(): void
    {
        $type = $this->type();
        $r = AnalyticsRange::fromQuery($_GET);
        $report = ReportBuilder::build($type, $r);
        View::render('reports/show', ['title' => $report['title'], 'report' => $report, 'meta' => $this->meta($report, $r), 'range' => $r, 'errors' => $r->errors, 'qs' => http_build_query($r->query()), 'type' => $type]);
    }

    public function print(): void
    {
        $type = $this->type();
        $r = $this->strictRange();
        $report = ReportBuilder::build($type, $r);
        Audit::log((int) Auth::user()['id'], 'export.print', 'report', $type, null, ['report' => $type, 'from' => $r->from->format('Y-m-d'), 'to' => $r->to->format('Y-m-d'), 'rows' => self::rowCount($report)]);
        View::render('reports/print', ['title' => $report['title'], 'report' => $report, 'meta' => $this->meta($report, $r)], 'print');
    }

    public function csv(): void
    {
        $type = $this->type();
        $r = $this->strictRange();
        $report = ReportBuilder::build($type, $r);
        $body = CsvWriter::document($report, $this->meta($report, $r));
        Audit::log((int) Auth::user()['id'], 'export.csv', 'report', $type, null, ['report' => $type, 'from' => $r->from->format('Y-m-d'), 'to' => $r->to->format('Y-m-d'), 'rows' => self::rowCount($report)]);
        $name = sprintf('babi-%s_%s_to_%s.csv', $type, $r->from->format('Y-m-d'), $r->to->format('Y-m-d'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }
}
