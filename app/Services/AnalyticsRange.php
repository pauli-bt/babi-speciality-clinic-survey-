<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Clock;
use DateTimeImmutable;

/**
 * Resolves the reporting period from the query string. All dates are clinic-local calendar days (APP_TIMEZONE),
 * both ends inclusive. SQL uses [start, end) with end = day after the last day, which keeps every boundary exact.
 */
final class AnalyticsRange
{
    public const PRESETS = ['today' => 'Today', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'this_month' => 'This month', 'custom' => 'Custom range'];
    public const DEFAULT = 'last30';
    public const MAX_DAYS = 1095;

    public function __construct(
        public  string $preset,
        public  DateTimeImmutable $from,
        public  DateTimeImmutable $to,
        public  array $errors = [],
    ) {
    }

    /** @param array<string, mixed> $q */
    public static function fromQuery(array $q, ?DateTimeImmutable $now = null): self
    {
        $today = ($now ?? Clock::now())->setTime(0, 0);
        $errors = [];
        $preset = (string) ($q['range'] ?? self::DEFAULT);
        if (!isset(self::PRESETS[$preset])) {
            $errors[] = 'Unknown date range; showing ' . strtolower(self::PRESETS[self::DEFAULT]) . '.';
            $preset = self::DEFAULT;
        }
        if ($preset === 'custom') {
            $f = Clock::parseDate((string) ($q['from'] ?? ''));
            $t = Clock::parseDate((string) ($q['to'] ?? ''));
            $err = null;
            if (!$f || !$t) { $err = 'Enter a valid start and end date.'; }
            elseif ($f > $t) { $err = 'The start date cannot be after the end date.'; }
            elseif ($t > $today) { $err = 'The end date cannot be in the future.'; }
            elseif ((int) $f->diff($t)->days + 1 > self::MAX_DAYS) { $err = 'The range cannot be longer than ' . self::MAX_DAYS . ' days.'; }
            if ($err === null) {
                return new self('custom', $f, $t, []);
            }
            $errors[] = $err . ' Showing ' . strtolower(self::PRESETS[self::DEFAULT]) . '.';
            $preset = self::DEFAULT;
        }
        $to = $today;
        $from = match ($preset) {
            'today' => $today,
            'last7' => $today->modify('-6 days'),
            'this_month' => $today->modify('first day of this month'),
            default => $today->modify('-29 days'),
        };
        return new self($preset, $from, $to, $errors);
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    public function startSql(): string { return $this->from->format('Y-m-d 00:00:00'); }

    /** Exclusive upper bound: midnight after the last inclusive day. */
    public function endSql(): string { return $this->to->modify('+1 day')->format('Y-m-d 00:00:00'); }

    /** The equally long period immediately before this one. */
    public function previous(): self
    {
        $n = $this->days();
        $pt = $this->from->modify('-1 day');
        return new self('previous', $pt->modify('-' . ($n - 1) . ' days'), $pt);
    }

    public function label(): string
    {
        return $this->from == $this->to ? $this->from->format('j M Y') : $this->from->format('j M Y') . ' to ' . $this->to->format('j M Y');
    }

    /** 'day' up to 62 days, 'week' up to 400, otherwise 'month'. */
    public function granularity(): string
    {
        $d = $this->days();
        return $d <= 62 ? 'day' : ($d <= 400 ? 'week' : 'month');
    }

    /** @return array<string, string> bucket key => label, in order, covering the whole period (zero-filled). */
    public function buckets(): array
    {
        $g = $this->granularity();
        $out = [];
        for ($d = $this->from; $d <= $this->to; $d = $d->modify('+1 day')) {
            $k = self::bucketKey($d, $g);
            $out[$k] ??= ($g === 'week' ? 'Wk of ' . $k : $k);
        }
        return $out;
    }

    public static function bucketKey(DateTimeImmutable $d, string $g): string
    {
        return match ($g) {
            'week' => $d->modify('monday this week')->format('Y-m-d'),
            'month' => $d->format('Y-m'),
            default => $d->format('Y-m-d'),
        };
    }

    public function query(): array
    {
        return $this->preset === 'custom' ? ['range' => 'custom', 'from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')] : ['range' => $this->preset];
    }
}
