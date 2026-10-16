<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Channel;

/**
 * Builds report tables for a period by REUSING the Phase 7 Analytics service (no SQL is duplicated here).
 * Every report is aggregate-only: no customer names, codes, phone numbers or interaction notes are ever included.
 * A table is: title, columns[], rows[][] (int|float|string|null; null = not defined / not applicable), optional note.
 */
final class ReportBuilder
{
    public const TYPES = [
        'summary' => ['Summary for the period', 'Key figures with the previous-period comparison where it is defined.'],
        'acquisition' => ['Customer acquisition', 'New customers by original source, channel and platform, and over time.'],
        'channels' => ['Interactions and channel performance', 'Volume, booking outcomes and rates per channel; appointments by originating channel.'],
        'campaigns' => ['Campaign responses and performance', 'Responses, new vs returning customers and booking outcomes per campaign.'],
        'appointments' => ['Appointment bookings and outcomes', 'Appointments booked in the period by current status, with outcome rates.'],
        'services' => ['Service popularity', 'Appointments booked and interactions per service.'],
        'status_movements' => ['Appointment status movements', 'Appointments that changed status in the period, next to the booking cohort.'],
    ];

    private const STATUS_LABELS = ['requested' => 'Requested', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];

    public static function valid(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return array{type: string, title: string, notes: string[], sections: array<int, array>} */
    public static function build(string $type, AnalyticsRange $r): array
    {
        $notes = ['Counts are aggregates only; no customer names, codes, phone numbers or notes are included.',
            'No cost or revenue data is stored, so revenue, profit, advertising ROI and cost per acquisition are not reported.'];
        $sections = match ($type) {
            'summary' => self::summary($r),
            'acquisition' => self::acquisition($r),
            'channels' => self::channels($r),
            'campaigns' => self::campaigns($r),
            'appointments' => self::appointments($r),
            'services' => self::services($r),
            'status_movements' => self::statusMovements($r),
        };
        return ['type' => $type, 'title' => self::TYPES[$type][0], 'notes' => $notes, 'sections' => $sections];
    }

    private static function series(array $s, string $col): array
    {
        $rows = [];
        foreach ($s['labels'] as $i => $l) { $rows[] = [$l, $s['values'][$i]]; }
        return ['title' => 'Over time', 'columns' => ['Period', $col], 'rows' => $rows];
    }

    private static function statusRows(array $st): array
    {
        $rows = [];
        foreach (self::STATUS_LABELS as $k => $l) { $rows[] = [$l, $st[$k]]; }
        return $rows;
    }

    private static function summary(AnalyticsRange $r): array
    {
        $p = $r->previous();
        $new = Analytics::newCustomers($r); $newP = Analytics::newCustomers($p);
        $int = Analytics::interactions($r); $intP = Analytics::interactions($p);
        $st = Analytics::bookingsByStatus($r); $book = array_sum($st); $bookP = array_sum(Analytics::bookingsByStatus($p));
        $o = Analytics::outcomeRates($st);
        $cmp = static fn (string $m, int $now, int $prev, string $def) => [$m, $now, $prev, Analytics::change($now, $prev), $def];
        $rows = [
            ['Total customers (as of generation)', Analytics::totalCustomers(), null, null, 'All customers on record, merged duplicates excluded. Not period based.'],
            $cmp('New customers acquired', $new, $newP, 'First-ever contact (acquisition date) in the period, by original source. Returning customers are not counted.'),
            $cmp('Interactions recorded', $int['total'], $intP['total'], 'Inquiries/visits dated in the period. Not appointments.'),
            ['Distinct customers with an interaction', $int['customers'], $intP['customers'], Analytics::change($int['customers'], $intP['customers']), 'Each customer once per period.'],
            $cmp('Appointment bookings', $book, $bookP, 'Appointments created by an interaction dated in the period, each counted once.'),
        ];
        foreach (self::STATUS_LABELS as $k => $l) {
            $rows[] = ["Booked in period, currently $l", $st[$k], null, null, 'Current status of the booking cohort. Not compared: older bookings have had longer to close.'];
        }
        $rows[] = ['Open (requested + confirmed)', $o['open'], null, null, 'Not yet closed.'];
        $rows[] = ['Closed (completed + cancelled + no-show)', $o['closed'], null, null, 'Denominator of the outcome rates.'];
        $rows[] = ['Completion rate (%)', $o['completion'], null, null, 'completed / closed; empty when nothing is closed.'];
        $rows[] = ['Cancellation rate (%)', $o['cancellation'], null, null, 'cancelled / closed.'];
        $rows[] = ['No-show rate (%)', $o['no_show'], null, null, 'no-show / closed.'];
        return [[
            'title' => 'Key figures: ' . $r->label() . ' (compared with ' . $p->label() . ')',
            'columns' => ['Metric', 'This period', 'Previous period', 'Change (%)', 'Definition'], 'rows' => $rows,
            'note' => 'Change (%) is empty when the previous value is 0. A booking is not a completed visit; a confirmed appointment is not a completed one.',
        ]];
    }

    private static function acquisition(AnalyticsRange $r): array
    {
        $src = Analytics::acquisitionBySource($r); $total = array_sum(array_map('intval', array_column($src, 'n')));
        $rows = [];
        foreach ($src as $s) {
            $rows[] = [$s['name'], $s['category'], (int) $s['n'], Analytics::rate((int) $s['n'], $total), (int) $s['with_appt'], (int) $s['with_completed']];
        }
        $ch = Analytics::acquisitionByChannel($r); $chRows = [];
        foreach (Channel::LABELS as $k => $l) { $chRows[] = [$l, $ch[$k]]; }
        $plRows = array_map(static fn ($x) => [$x['name'], (int) $x['n']], Analytics::acquisitionByPlatform($r));
        return [
            ['title' => 'New customers by original acquisition source', 'columns' => ['Original source', 'Category', 'New customers', 'Share (%)', 'With 1+ appointment (to date)', 'With 1+ completed appointment (to date)'], 'rows' => $rows,
                'note' => 'Acquisition date = first-ever contact. Original source is write-once (changed only by audited correction). The last two columns count each customer once, using appointments to date.'],
            ['title' => 'New customers by acquisition channel', 'columns' => ['Channel', 'New customers'], 'rows' => $chRows],
            ['title' => 'Original social platform', 'columns' => ['Platform', 'New customers'], 'rows' => $plRows],
            self::series(Analytics::newCustomersSeries($r), 'New customers') + ['title' => 'New customers over time (' . $r->granularity() . ')'],
        ];
    }

    private static function channels(AnalyticsRange $r): array
    {
        $rows = [];
        foreach (Analytics::channelInteractions($r) as $k => $v) {
            $rec = $k === 'reception';
            $rows[] = [Channel::LABELS[$k], $v['interactions'], $v['customers'],
                $rec ? null : $v['not_booked'], $rec ? null : $v['requested'], $rec ? null : $v['confirmed'], $rec ? null : $v['booking_decided'], $rec ? null : $v['booking_rate'],
                $rec ? $v['used'] : null, $rec ? $v['not_used'] : null, $rec ? $v['unknown'] : null, $rec ? $v['usage_decided'] : null, $rec ? $v['usage_rate'] : null];
        }
        $ap = [];
        foreach (Analytics::appointmentsByChannel($r) as $k => $s) {
            $t = array_sum($s); if ($k === 'none' && $t === 0) { continue; }
            $o = Analytics::outcomeRates($s);
            $ap[] = [$k === 'none' ? 'No originating interaction' : Channel::LABELS[$k], $t, $o['open'], $s['completed'], $s['cancelled'], $s['no_show'], $o['completion']];
        }
        $ser = Analytics::interactionsByChannelSeries($r); $sr = [];
        foreach ($ser['labels'] as $i => $l) { $sr[] = [$l, $ser['channels']['reception'][$i], $ser['channels']['phone'][$i], $ser['channels']['social_dm'][$i]]; }
        return [
            ['title' => 'Channel performance', 'columns' => ['Channel', 'Interactions', 'Distinct customers', 'Not booked', 'Requested', 'Confirmed', 'Booking outcomes recorded', 'Booking rate (%)', 'Service used', 'Service not used', 'Service unknown', 'Use outcomes decided', 'Service-use rate (%)'], 'rows' => $rows,
                'note' => 'Booking rate = (requested + confirmed) / (not booked + requested + confirmed); telephone and social DM only. Service-use rate = used / (used + not used); reception only; unknown excluded. Empty = not applicable or zero denominator. Reception does not book appointments.'],
            ['title' => 'Appointments booked in the period by originating channel (current status)', 'columns' => ['Channel', 'Appointments', 'Open', 'Completed', 'Cancelled', 'No-show', 'Completion rate (%)'], 'rows' => $ap,
                'note' => 'Each appointment is counted once, under the channel of the interaction that created it. Completion rate = completed / (completed + cancelled + no-show).'],
            ['title' => 'Interactions over time by channel (' . $r->granularity() . ')', 'columns' => ['Period', 'Reception', 'Telephone', 'Social media DM'], 'rows' => $sr],
        ];
    }

    private static function campaigns(AnalyticsRange $r): array
    {
        $rows = [];
        foreach (Analytics::campaigns($r) as $c) {
            $o = Analytics::outcomeRates($c['appts']);
            $rows[] = [$c['name'], $c['platform'] ?? 'Offline / none', CampaignManager::STATUSES[$c['status']], (int) $c['responses'], (int) $c['customers'], (int) $c['first_contact'], (int) $c['returning_resp'], (int) $c['acquired'],
                (int) $c['with_booking'], (int) $c['booking_decided'], $c['booking_rate'], $c['bookings'], $o['open'], $c['appts']['completed'], $c['appts']['cancelled'], $c['appts']['no_show']];
        }
        return [['title' => 'Campaign responses and bookings', 'columns' => ['Campaign', 'Platform', 'Status', 'Responses', 'Distinct customers', 'Responses from new customers', 'Responses from returning customers', 'Customers acquired (original attribution)', 'Responses with booking', 'Responses with booking outcome', 'Booking rate (%)', 'Appointments', 'Open', 'Completed', 'Cancelled', 'No-show'], 'rows' => $rows,
            'note' => 'A response is an interaction where staff selected the campaign; responses are not new customers. Returning customers are re-engagement. Interactions without a selected campaign are unknown, not non-campaign. Appointments are those created by the responses, each once, by current status. Draft campaigns without activity are omitted. No cost or revenue data exists, so no ROI or cost per acquisition is shown.']];
    }

    private static function appointments(AnalyticsRange $r): array
    {
        $st = Analytics::bookingsByStatus($r); $total = array_sum($st); $o = Analytics::outcomeRates($st);
        $rows = [];
        foreach (self::STATUS_LABELS as $k => $l) { $rows[] = [$l, $st[$k], Analytics::rate($st[$k], $total)]; }
        return [
            ['title' => 'Appointments booked in the period, by current status', 'columns' => ['Status', 'Appointments', 'Share of bookings (%)'], 'rows' => $rows,
                'note' => 'Counts appointments created by an interaction dated in the period (each once, regardless of history rows or linked interactions), by status today.'],
            ['title' => 'Outcome rates (closed appointments only)', 'columns' => ['Measure', 'Value'], 'rows' => [['Open (requested + confirmed)', $o['open']], ['Closed (completed + cancelled + no-show)', $o['closed']], ['Completion rate (%)', $o['completion']], ['Cancellation rate (%)', $o['cancellation']], ['No-show rate (%)', $o['no_show']]],
                'note' => 'Rates are empty when nothing has closed. Completed means staff marked it completed.'],
            self::series(Analytics::bookingsSeries($r), 'Appointment bookings') + ['title' => 'Bookings over time (' . $r->granularity() . ')'],
        ];
    }

    private static function services(AnalyticsRange $r): array
    {
        $rows = [];
        foreach (Analytics::services($r) as $s) {
            $o = Analytics::outcomeRates($s['appts']);
            $rows[] = [$s['name'], $s['is_active'] ? 'Active' : 'Inactive', $s['interest'], $s['bookings'], $o['open'], $s['appts']['completed'], $s['appts']['cancelled'], $s['appts']['no_show'], $o['completion']];
        }
        return [['title' => 'Services', 'columns' => ['Service', 'Service status', 'Interactions naming the service', 'Appointments booked', 'Open', 'Completed', 'Cancelled', 'No-show', 'Completion rate (%)'], 'rows' => $rows,
            'note' => 'Appointments are those booked in the period by current status. "Interactions naming the service" is the service of interest/used/booked on the interaction: it measures inquiry popularity, not delivered services.']];
    }

    private static function statusMovements(AnalyticsRange $r): array
    {
        $moved = Analytics::movedIntoStatus($r); $st = Analytics::bookingsByStatus($r);
        return [
            ['title' => 'Appointments that moved into a status during the period', 'columns' => ['Status', 'Appointments'], 'rows' => self::statusRows($moved),
                'note' => 'Distinct appointments with a status change in the period, whatever month they were booked; the creation event is excluded. An appointment can appear under more than one status.'],
            ['title' => 'For comparison: appointments booked in the period, current status', 'columns' => ['Status', 'Appointments'], 'rows' => self::statusRows($st),
                'note' => 'A different question: when the appointment was created, not when its status changed.'],
        ];
    }
}
