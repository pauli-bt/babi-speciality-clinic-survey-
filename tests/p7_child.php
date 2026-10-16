<?php
declare(strict_types=1);
// Child for tests/phase7.php. Modes: "range <preset> <UTC instant> [from to]" and "metrics <from> <to>". Prints JSON.
putenv('APP_ENV=testing');
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\Clock;
use App\Services\{Analytics as A, AnalyticsRange};

if ($argv[1] === 'range') {
    $now = (new DateTimeImmutable($argv[3], new DateTimeZone('UTC')))->setTimezone(Clock::zone());
    $q = ['range' => $argv[2]] + (isset($argv[4]) ? ['from' => $argv[4], 'to' => $argv[5]] : []);
    $r = AnalyticsRange::fromQuery($q, $now); $p = $r->previous();
    echo json_encode(['tz' => date_default_timezone_get(), 'preset' => $r->preset, 'from' => $r->from->format('Y-m-d'), 'to' => $r->to->format('Y-m-d'), 'days' => $r->days(),
        'start' => $r->startSql(), 'end' => $r->endSql(), 'pfrom' => $p->from->format('Y-m-d'), 'pto' => $p->to->format('Y-m-d'), 'gran' => $r->granularity(), 'buckets' => count($r->buckets()),
        'errors' => $r->errors, 'first_bucket' => array_key_first($r->buckets())]), PHP_EOL;
    exit;
}
$r = AnalyticsRange::fromQuery(['range' => 'custom', 'from' => $argv[2], 'to' => $argv[3]], new DateTimeImmutable('2030-01-01', Clock::zone()));
$st = A::bookingsByStatus($r);
echo json_encode([
    'tz' => date_default_timezone_get(), 'errors' => $r->errors, 'new' => A::newCustomers($r), 'prev_new' => A::newCustomers($r->previous()), 'int' => A::interactions($r), 'prev_int' => A::interactions($r->previous()),
    'status' => $st, 'rates' => A::outcomeRates($st), 'prev_book' => array_sum(A::bookingsByStatus($r->previous())), 'moved' => A::movedIntoStatus($r),
    'source' => A::acquisitionBySource($r), 'acq_ch' => A::acquisitionByChannel($r), 'platform' => A::acquisitionByPlatform($r),
    'channels' => A::channelInteractions($r), 'appt_ch' => A::appointmentsByChannel($r), 'campaigns' => A::campaigns($r), 'services' => A::services($r),
    'series_new' => A::newCustomersSeries($r), 'series_book' => A::bookingsSeries($r),
]), PHP_EOL;
