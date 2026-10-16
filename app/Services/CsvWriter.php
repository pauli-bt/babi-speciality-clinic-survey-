<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Minimal RFC 4180 CSV writer with spreadsheet formula-injection protection.
 * - Strings a spreadsheet could read as a formula (leading = + - @ tab or CR, also after leading whitespace)
 *   are prefixed with a single quote character, so they are displayed as text and never evaluated.
 * - int/float cells are written as numbers untouched (so legitimate negatives such as -20.5 stay numeric).
 * - Fields containing comma, quote, CR, LF or leading/trailing space are quoted, quotes doubled.
 * - Lines end in CRLF; the file starts with a UTF-8 BOM so Excel on Windows opens it as UTF-8.
 */
final class CsvWriter
{
    private const FORMULA_START = '/^[\s]*[=+\-@\t\r]/u';

    public static function cell(int|float|string|null $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
        }
        $s = str_replace("\0", '', $v);
        if ($s !== '' && preg_match(self::FORMULA_START, $s)) {
            $s = "'" . $s;
        }
        if ($s !== '' && (strpbrk($s, ",\"\r\n") !== false || $s !== trim($s))) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    /** @param array<int|string, int|float|string|null> $row */
    public static function row(array $row): string
    {
        return implode(',', array_map([self::class, 'cell'], array_values($row))) . "\r\n";
    }

    /** Whole document from a built report (see ReportBuilder) plus metadata rows. */
    public static function document(array $report, array $meta): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ($meta as $k => $v) {
            $out .= self::row([$k, $v]);
        }
        foreach ($report['notes'] as $n) {
            $out .= self::row(['Note', $n]);
        }
        foreach ($report['sections'] as $sec) {
            $out .= "\r\n" . self::row([$sec['title']]) . self::row($sec['columns']);
            foreach ($sec['rows'] as $r) {
                $out .= self::row($r);
            }
            if (!empty($sec['note'])) {
                $out .= self::row(['Note', $sec['note']]);
            }
        }
        return $out;
    }
}
