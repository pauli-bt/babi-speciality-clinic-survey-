<?php
declare(strict_types=1);

namespace App\Helpers;

/** Reads configuration from real environment variables first, then the .env file. */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
            if ($i === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line; // UTF-8 BOM added by some Windows editors
            }
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$vars[trim($key)] = self::parseValue(trim($value));
        }
    }

    /** "quoted value" / 'quoted value' (an optional trailing # comment is ignored); unquoted: text before " #" is the value. */
    private static function parseValue(string $value): string
    {
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            if (preg_match('/^(["\'])(.*?)\1\s*(?:#.*)?$/', $value, $m)) {
                return $m[2];
            }
            return $value;
        }
        return rtrim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return self::$vars[$key] ?? $default;
    }

    /**
     * Fail safe: anything other than an explicit "local" or "testing" (unset, empty, a typo such as "prod") is production,
     * so a misspelt APP_ENV can never silently switch off HTTPS enforcement and Secure cookies.
     */
    public static function isProduction(): bool
    {
        return !in_array(strtolower(trim((string) self::get('APP_ENV', 'production'))), ['local', 'testing'], true);
    }
}
