<?php
declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Single source of truth for the application timezone (APP_TIMEZONE, default Africa/Addis_Ababa).
 * DATETIME columns hold clinic-local wall-clock time; PHP and the MySQL session must agree on it.
 */
final class Clock
{
    public const DEFAULT_TZ = 'Africa/Addis_Ababa';

    /** Validate and return the configured zone name. Throws with a clear message on invalid values. */
    public static function configuredName(): string
    {
        $name = trim((string) Env::get('APP_TIMEZONE', self::DEFAULT_TZ));
        if ($name === '') {
            $name = self::DEFAULT_TZ;
        }
        // Reject offsets/abbreviations ("+03:00", "EAT"): only named IANA zones have defined DST rules.
        if (!in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) && $name !== 'UTC') {
            throw new RuntimeException("Invalid APP_TIMEZONE \"$name\". Use an IANA name such as Africa/Addis_Ababa.");
        }
        return $name;
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::configuredName());
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /** MySQL session offset string such as "+03:00" or "-05:00" for the given instant (default: now). */
    public static function mysqlOffset(?DateTimeImmutable $at = null): string
    {
        $at ??= self::now();
        $secs = self::zone()->getOffset($at);
        $sign = $secs < 0 ? '-' : '+';
        $secs = abs($secs);
        return sprintf('%s%02d:%02d', $sign, intdiv($secs, 3600), intdiv($secs % 3600, 60));
    }

    /** Parses a form date/time as clinic-local wall-clock time; null when invalid. */
    public static function parseLocal(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        foreach (['Y-m-d\\TH:i', 'Y-m-d H:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!' . $fmt, $raw, self::zone());
            $e = DateTimeImmutable::getLastErrors();
            if ($d && (!$e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) {
                return $d;
            }
        }
        return null;
    }

    /** Parses Y-m-d strictly; null when invalid. */
    public static function parseDate(string $raw): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw), self::zone());
        $e = DateTimeImmutable::getLastErrors();
        return ($d && (!$e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) ? $d : null;
    }
}
