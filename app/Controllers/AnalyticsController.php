<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Channel, View};
use App\Services\{Analytics, AnalyticsRange, Auth};

/** Aggregate analytics. Authorization is enforced by the router (`analytics.marketing`); no page here selects personal data. */
final class AnalyticsController
{
    private function base(string $tab, string $title, string $path): array
    {
        $range = AnalyticsRange::fromQuery($_GET);
        return ['title' => $title, 'useCharts' => true, 'tab' => $tab, 'path' => $path, 'range' => $range, 'errors' => $range->errors, 'qs' => http_build_query($range->query())];
    }

    public function overview(string $path = '/analytics', string $title = 'Analytics overview'): void
    {
        $d = $this->base('overview', $title, $path);
        $r = $d['range']; $p = $r->previous();
        $status = Analytics::bookingsByStatus($r);
        $int = Analytics::interactions($r); $intPrev = Analytics::interactions($p);
        $new = Analytics::newCustomers($r); $newPrev = Analytics::newCustomers($p);
        $bookings = array_sum($status); $bookingsPrev = array_sum(Analytics::bookingsByStatus($p));
        $sources = Analytics::acquisitionBySource($r);
        $channels = Analytics::channelInteractions($r);
        $busiest = null;
        foreach ($channels as $ch => $v) { if ($v['interactions'] > 0 && ($busiest === null || $v['interactions'] > $channels[$busiest]['interactions'])) { $busiest = $ch; } }
        View::render('analytics/overview', $d + [
            'totalCustomers' => Analytics::totalCustomers(), 'new' => $new, 'newPrev' => $newPrev, 'int' => $int, 'intPrev' => $intPrev,
            'bookings' => $bookings, 'bookingsPrev' => $bookingsPrev, 'status' => $status, 'rates' => Analytics::outcomeRates($status),
            'newSeries' => Analytics::newCustomersSeries($r), 'intSeries' => Analytics::interactionsSeries($r), 'bookSeries' => Analytics::bookingsSeries($r),
            'topSource' => $sources[0] ?? null, 'busiest' => $busiest, 'busiestN' => $busiest ? $channels[$busiest]['interactions'] : 0, 'channelLabels' => Channel::LABELS, 'prevLabel' => $p->label(),
            'quality' => Auth::can('analytics.full') ? Analytics::dataQuality($r) : null,
        ]);
    }

    public function acquisition(): void
    {
        $d = $this->base('acquisition', 'Customer acquisition', '/analytics/acquisition');
        $r = $d['range'];
        View::render('analytics/acquisition', $d + ['bySource' => Analytics::acquisitionBySource($r), 'byChannel' => Analytics::acquisitionByChannel($r), 'byPlatform' => Analytics::acquisitionByPlatform($r),
            'series' => Analytics::newCustomersSeries($r), 'channelLabels' => Channel::LABELS]);
    }

    public function channels(): void
    {
        $d = $this->base('channels', 'Channel performance', '/analytics/channels');
        $r = $d['range'];
        View::render('analytics/channels', $d + ['ch' => Analytics::channelInteractions($r), 'appts' => Analytics::appointmentsByChannel($r),
            'series' => Analytics::interactionsByChannelSeries($r), 'channelLabels' => Channel::LABELS]);
    }

    public function campaigns(): void
    {
        $d = $this->base('campaigns', 'Campaign performance', '/analytics/campaigns');
        View::render('analytics/campaigns', $d + ['campaigns' => Analytics::campaigns($d['range'])]);
    }

    public function appointments(): void
    {
        $d = $this->base('appointments', 'Appointments & services', '/analytics/appointments');
        $r = $d['range'];
        $status = Analytics::bookingsByStatus($r);
        View::render('analytics/appointments', $d + ['status' => $status, 'rates' => Analytics::outcomeRates($status), 'moved' => Analytics::movedIntoStatus($r), 'all' => Analytics::allByStatus(),
            'series' => Analytics::bookingsSeries($r), 'services' => Analytics::services($r), 'apptCh' => Analytics::appointmentsByChannel($r), 'channelLabels' => Channel::LABELS + ['none' => 'No originating interaction']]);
    }
}
