<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Models\Setting;

final class Phone
{
    /** Digits only; "+251 911 23 45 67", "00251911234567" and "0911234567" all become 0911234567. */
    public static function normalize(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        $cc = Setting::get('phone_country_code', '251');
        if ($cc !== '' && str_starts_with($d, $cc) && strlen($d) >= strlen($cc) + 8) {
            $d = '0' . substr($d, strlen($cc));
        }
        return $d;
    }

    /** Shows only the last four digits, e.g. ••••••4567. */
    public static function mask(?string $normalized): string
    {
        if ($normalized === null || $normalized === '') {
            return '';
        }
        $n = strlen($normalized);
        return $n <= 4 ? $normalized : str_repeat('•', $n - 4) . substr($normalized, -4);
    }
}
