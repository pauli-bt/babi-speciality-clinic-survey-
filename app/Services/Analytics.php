<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Db;
use DateTimeImmutable;

/**
 * Aggregate analytics. Every figure is computed by SQL aggregation (nothing is loaded row by row) and contains no
 * personal data: no names, phone numbers, notes or customer codes are ever selected here.
 *
 * Definitions (see docs/PHASE7_ANALYTICS.md):
 *  - Acquisition date  = customers.first_acquisition_at; attribution = the stored ORIGINAL source (write-once).
 *  - Interaction date  = interactions.interaction_at.
 *  - Booking date      = interaction_at of the appointment's origin interaction (created_at when there is none).
 *    "Bookings in a period" is a cohort of appointments (one row per appointment, never per history event or link),
 *    reported by their CURRENT status.
 */
final class Analytics
{
    public const STATUSES = ['requested', 'confirmed', 'completed', 'cancelled', 'no_show'];

    /** Booking date expression; requires the appointments alias `a` and a LEFT JOIN of the origin interaction as `oi`. */
    private const BOOKED_AT = 'COALESCE(oi.interaction_at, a.created_at)';
    private const BOOKINGS_FROM = 'FROM appointments a LEFT JOIN interactions oi ON oi.id = a.origin_interaction_id';

    /** Percentage with a safe zero denominator: null means "not defined". */
    public static function rate(int|float $num, int|float $den): ?float
    {
        return $den > 0 ? round($num / $den * 100, 1) : null;
    }

    /** Change versus the previous period; null when the previous value is 0 (not defined). */
    public static function change(int $now, int $prev): ?float
    {
        return $prev > 0 ? round(($now - $prev) / $prev * 100, 1) : null;
    }

    private static function one(string $sql, array $args): mixed
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }

    private static function all(string $sql, array $args): array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    private static function statusMap(array $rows, string $key = 'status'): array
    {
        $m = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) { $m[$r[$key]] = (int) $r['n']; }
        return $m;
    }

    /** Appointment outcome rates over closed appointments: completed + cancelled + no_show. */
    public static function outcomeRates(array $s): array
    {
        $closed = $s['completed'] + $s['cancelled'] + $s['no_show'];
        return ['closed' => $closed, 'open' => $s['requested'] + $s['confirmed'],
            'completion' => self::rate($s['completed'], $closed), 'cancellation' => self::rate($s['cancelled'], $closed), 'no_show' => self::rate($s['no_show'], $closed)];
    }

    // ---------- Summary ----------
    public static function totalCustomers(): int
    {
        return (int) self::one("SELECT COUNT(*) FROM customers WHERE status <> 'merged'", []);
    }

    public static function newCustomers(AnalyticsRange $r): int
    {
        return (int) self::one("SELECT COUNT(*) FROM customers WHERE status <> 'merged' AND first_acquisition_at >= ? AND first_acquisition_at < ?", [$r->startSql(), $r->endSql()]);
    }

    /** @return array{total: int, customers: int} */
    public static function interactions(AnalyticsRange $r): array
    {
        $row = self::all('SELECT COUNT(*) AS total, COUNT(DISTINCT customer_id) AS customers FROM interactions WHERE interaction_at >= ? AND interaction_at < ?', [$r->startSql(), $r->endSql()])[0];
        return ['total' => (int) $row['total'], 'customers' => (int) $row['customers']];
    }

    /** Appointments booked in the period, by current status. */
    public static function bookingsByStatus(AnalyticsRange $r): array
    {
        return self::statusMap(self::all('SELECT a.status, COUNT(*) AS n ' . self::BOOKINGS_FROM . ' WHERE ' . self::BOOKED_AT . ' >= ? AND ' . self::BOOKED_AT . ' < ? GROUP BY a.status', [$r->startSql(), $r->endSql()]));
    }

    /** Distinct appointments that moved INTO a status during the period (excludes the creation event). */
    public static function movedIntoStatus(AnalyticsRange $r): array
    {
        return self::statusMap(self::all('SELECT to_status AS status, COUNT(DISTINCT appointment_id) AS n FROM appointment_events WHERE from_status IS NOT NULL AND event_at >= ? AND event_at < ? GROUP BY to_status', [$r->startSql(), $r->endSql()]));
    }

    /** All appointments ever, by current status (not period based). */
    public static function allByStatus(): array
    {
        return self::statusMap(self::all('SELECT status, COUNT(*) AS n FROM appointments GROUP BY status', []));
    }

    // ---------- Time series ----------
    /** Sums per-day aggregates into the period's buckets (zero-filled). */
    private static function series(AnalyticsRange $r, array $dayRows, string $dayKey = 'd', string $valKey = 'n'): array
    {
        $g = $r->granularity();
        $b = array_fill_keys(array_keys($r->buckets()), 0);
        foreach ($dayRows as $row) {
            $k = AnalyticsRange::bucketKey(new DateTimeImmutable((string) $row[$dayKey]), $g);
            if (isset($b[$k])) { $b[$k] += (int) $row[$valKey]; }
        }
        return ['labels' => array_values($r->buckets()), 'values' => array_values($b)];
    }

    public static function newCustomersSeries(AnalyticsRange $r): array
    {
        return self::series($r, self::all("SELECT DATE(first_acquisition_at) AS d, COUNT(*) AS n FROM customers WHERE status <> 'merged' AND first_acquisition_at >= ? AND first_acquisition_at < ? GROUP BY d", [$r->startSql(), $r->endSql()]));
    }

    public static function interactionsSeries(AnalyticsRange $r): array
    {
        return self::series($r, self::all('SELECT DATE(interaction_at) AS d, COUNT(*) AS n FROM interactions WHERE interaction_at >= ? AND interaction_at < ? GROUP BY d', [$r->startSql(), $r->endSql()]));
    }

    public static function bookingsSeries(AnalyticsRange $r): array
    {
        return self::series($r, self::all('SELECT DATE(' . self::BOOKED_AT . ') AS d, COUNT(*) AS n ' . self::BOOKINGS_FROM . ' WHERE ' . self::BOOKED_AT . ' >= ? AND ' . self::BOOKED_AT . ' < ? GROUP BY d', [$r->startSql(), $r->endSql()]));
    }

    /** Interactions per channel over time: channel => values. */
    public static function interactionsByChannelSeries(AnalyticsRange $r): array
    {
        $rows = self::all('SELECT DATE(interaction_at) AS d, channel, COUNT(*) AS n FROM interactions WHERE interaction_at >= ? AND interaction_at < ? GROUP BY d, channel', [$r->startSql(), $r->endSql()]);
        $out = [];
        foreach (['reception', 'phone', 'social_dm'] as $ch) {
            $out[$ch] = self::series($r, array_filter($rows, static fn ($x) => $x['channel'] === $ch))['values'];
        }
        return ['labels' => array_values($r->buckets()), 'channels' => $out];
    }

    // ---------- Acquisition ----------
    /** New customers by original source, with how many have booked / had a completed appointment to date. */
    public static function acquisitionBySource(AnalyticsRange $r): array
    {
        return self::all("SELECT s.name, s.category, COUNT(*) AS n,
                SUM(EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = c.id)) AS with_appt,
                SUM(EXISTS (SELECT 1 FROM appointments a WHERE a.customer_id = c.id AND a.status = 'completed')) AS with_completed
            FROM customers c JOIN acquisition_sources s ON s.id = c.original_source_id
            WHERE c.status <> 'merged' AND c.first_acquisition_at >= ? AND c.first_acquisition_at < ?
            GROUP BY s.id, s.name, s.category ORDER BY n DESC, s.name", [$r->startSql(), $r->endSql()]);
    }

    public static function acquisitionByChannel(AnalyticsRange $r): array
    {
        $m = ['reception' => 0, 'phone' => 0, 'social_dm' => 0];
        foreach (self::all("SELECT first_acquisition_channel AS ch, COUNT(*) AS n FROM customers WHERE status <> 'merged' AND first_acquisition_at >= ? AND first_acquisition_at < ? GROUP BY ch", [$r->startSql(), $r->endSql()]) as $x) { $m[$x['ch']] = (int) $x['n']; }
        return $m;
    }

    /** Original social platform for customers acquired through social media. */
    public static function acquisitionByPlatform(AnalyticsRange $r): array
    {
        return self::all("SELECT p.name, COUNT(*) AS n FROM customers c JOIN social_platforms p ON p.id = c.original_social_platform_id WHERE c.status <> 'merged' AND c.first_acquisition_at >= ? AND c.first_acquisition_at < ? GROUP BY p.id, p.name ORDER BY n DESC, p.name", [$r->startSql(), $r->endSql()]);
    }

    // ---------- Channels ----------
    public static function channelInteractions(AnalyticsRange $r): array
    {
        $out = [];
        foreach (['reception', 'phone', 'social_dm'] as $ch) {
            $out[$ch] = ['interactions' => 0, 'customers' => 0, 'not_booked' => 0, 'requested' => 0, 'confirmed' => 0, 'used' => 0, 'not_used' => 0, 'unknown' => 0];
        }
        $rows = self::all("SELECT channel, COUNT(*) AS n, COUNT(DISTINCT customer_id) AS cust,
                SUM(booking_outcome = 'not_booked') AS not_booked, SUM(booking_outcome = 'requested') AS requested, SUM(booking_outcome = 'confirmed') AS confirmed,
                SUM(service_usage_outcome = 'used') AS used, SUM(service_usage_outcome = 'not_used') AS not_used, SUM(service_usage_outcome = 'unknown') AS unknown_use
            FROM interactions WHERE interaction_at >= ? AND interaction_at < ? GROUP BY channel", [$r->startSql(), $r->endSql()]);
        foreach ($rows as $x) {
            $out[$x['channel']] = ['interactions' => (int) $x['n'], 'customers' => (int) $x['cust'], 'not_booked' => (int) $x['not_booked'], 'requested' => (int) $x['requested'], 'confirmed' => (int) $x['confirmed'],
                'used' => (int) $x['used'], 'not_used' => (int) $x['not_used'], 'unknown' => (int) $x['unknown_use']];
        }
        foreach ($out as $ch => &$v) {
            $decided = $v['not_booked'] + $v['requested'] + $v['confirmed'];
            $v['booking_rate'] = $ch === 'reception' ? null : self::rate($v['requested'] + $v['confirmed'], $decided);
            $v['booking_decided'] = $decided;
            $usageDecided = $v['used'] + $v['not_used'];
            $v['usage_rate'] = $ch === 'reception' ? self::rate($v['used'], $usageDecided) : null;
            $v['usage_decided'] = $usageDecided;
        }
        return $out;
    }

    /** Appointments booked in the period by originating channel (+ 'none' when there is no origin interaction), by current status. */
    public static function appointmentsByChannel(AnalyticsRange $r): array
    {
        $out = [];
        foreach (['reception', 'phone', 'social_dm', 'none'] as $ch) { $out[$ch] = array_fill_keys(self::STATUSES, 0); }
        foreach (self::all("SELECT COALESCE(oi.channel, 'none') AS ch, a.status, COUNT(*) AS n " . self::BOOKINGS_FROM . ' WHERE ' . self::BOOKED_AT . ' >= ? AND ' . self::BOOKED_AT . ' < ? GROUP BY ch, a.status', [$r->startSql(), $r->endSql()]) as $x) {
            $out[$x['ch']][$x['status']] = (int) $x['n'];
        }
        return $out;
    }

    // ---------- Campaigns ----------
    public static function campaigns(AnalyticsRange $r): array
    {
        $rows = self::all("SELECT c.id, c.name, c.status, c.start_date, c.end_date, p.name AS platform,
                COUNT(i.id) AS responses, COUNT(DISTINCT i.customer_id) AS customers,
                COALESCE(SUM(i.is_first_contact = 1), 0) AS first_contact, COALESCE(SUM(i.is_first_contact = 0), 0) AS returning_resp,
                COALESCE(SUM(i.booking_outcome IN ('requested','confirmed')), 0) AS with_booking,
                COALESCE(SUM(i.booking_outcome IN ('not_booked','requested','confirmed')), 0) AS booking_decided
            FROM campaigns c LEFT JOIN social_platforms p ON p.id = c.platform_id
            LEFT JOIN interactions i ON i.campaign_id = c.id AND i.interaction_at >= ? AND i.interaction_at < ?
            GROUP BY c.id, c.name, c.status, c.start_date, c.end_date, p.name ORDER BY responses DESC, c.name", [$r->startSql(), $r->endSql()]);
        $acq = array_column(self::all("SELECT original_campaign_id AS id, COUNT(*) AS n FROM customers WHERE status <> 'merged' AND original_campaign_id IS NOT NULL AND first_acquisition_at >= ? AND first_acquisition_at < ? GROUP BY original_campaign_id", [$r->startSql(), $r->endSql()]), 'n', 'id');
        $appts = [];
        foreach (self::all('SELECT oi.campaign_id AS id, a.status, COUNT(*) AS n FROM appointments a JOIN interactions oi ON oi.id = a.origin_interaction_id WHERE oi.campaign_id IS NOT NULL AND oi.interaction_at >= ? AND oi.interaction_at < ? GROUP BY oi.campaign_id, a.status', [$r->startSql(), $r->endSql()]) as $x) {
            $appts[(int) $x['id']][$x['status']] = (int) $x['n'];
        }
        $out = [];
        foreach ($rows as $c) {
            $st = array_merge(array_fill_keys(self::STATUSES, 0), $appts[(int) $c['id']] ?? []);
            $c += ['acquired' => (int) ($acq[$c['id']] ?? 0), 'appts' => $st, 'bookings' => array_sum($st)];
            $c['booking_rate'] = self::rate((int) $c['with_booking'], (int) $c['booking_decided']);
            if ($c['status'] === 'draft' && (int) $c['responses'] === 0 && $c['acquired'] === 0) { continue; }
            $out[] = $c;
        }
        return $out;
    }

    // ---------- Services ----------
    /** Appointments booked in the period per service, by current status, plus interactions mentioning the service. */
    public static function services(AnalyticsRange $r): array
    {
        $map = [];
        foreach (self::all('SELECT s.id, s.name, s.is_active, a.status, COUNT(*) AS n ' . self::BOOKINGS_FROM . ' JOIN services s ON s.id = a.service_id WHERE ' . self::BOOKED_AT . ' >= ? AND ' . self::BOOKED_AT . ' < ? GROUP BY s.id, s.name, s.is_active, a.status', [$r->startSql(), $r->endSql()]) as $x) {
            $map[(int) $x['id']] ??= ['name' => $x['name'], 'is_active' => (int) $x['is_active'], 'appts' => array_fill_keys(self::STATUSES, 0), 'interest' => 0];
            $map[(int) $x['id']]['appts'][$x['status']] = (int) $x['n'];
        }
        foreach (self::all('SELECT s.id, s.name, s.is_active, COUNT(*) AS n FROM interactions i JOIN services s ON s.id = i.service_id WHERE i.interaction_at >= ? AND i.interaction_at < ? GROUP BY s.id, s.name, s.is_active', [$r->startSql(), $r->endSql()]) as $x) {
            $map[(int) $x['id']] ??= ['name' => $x['name'], 'is_active' => (int) $x['is_active'], 'appts' => array_fill_keys(self::STATUSES, 0), 'interest' => 0];
            $map[(int) $x['id']]['interest'] = (int) $x['n'];
        }
        foreach ($map as &$m) { $m['bookings'] = array_sum($m['appts']); }
        unset($m);
        uasort($map, static fn ($a, $b) => [$b['bookings'], $b['interest'], $a['name']] <=> [$a['bookings'], $a['interest'], $b['name']]);
        return array_values($map);
    }

    // ---------- Data quality (administrators only) ----------
    public static function dataQuality(AnalyticsRange $r): array
    {
        $a = [$r->startSql(), $r->endSql()];
        return [
            'needs_review' => (int) self::one("SELECT COUNT(*) FROM interactions WHERE record_status = 'needs_review' AND interaction_at >= ? AND interaction_at < ?", $a),
            'campaign_without_id' => (int) self::one("SELECT COUNT(*) FROM interactions WHERE interaction_reason = 'campaign_response' AND campaign_id IS NULL AND interaction_at >= ? AND interaction_at < ?", $a),
            'confirmed_past' => (int) self::one("SELECT COUNT(*) FROM appointments WHERE status = 'confirmed' AND appointment_at < NOW()", []),
            'stale_requested' => (int) self::one("SELECT COUNT(*) FROM appointments WHERE status = 'requested' AND appointment_at IS NULL AND created_at < NOW() - INTERVAL 7 DAY", []),
            'no_origin' => (int) self::one('SELECT COUNT(*) FROM appointments WHERE origin_interaction_id IS NULL', []),
        ];
    }
}
