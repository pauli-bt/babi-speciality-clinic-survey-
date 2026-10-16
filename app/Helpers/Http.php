<?php
declare(strict_types=1);

namespace App\Helpers;

final class Http
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Client address used for rate limiting and the audit trail. Behind a trusted reverse proxy (TRUST_PROXY=1) every
     * connection arrives from the proxy, so REMOTE_ADDR alone would make all users share one address. In that case the
     * RIGHTMOST X-Forwarded-For entry (the one our own proxy appended) is used; the leftmost entries are client-supplied
     * and spoofable, and the header is ignored entirely unless TRUST_PROXY=1.
     */
    public static function ip(): string
    {
        $remote = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        if (Env::get('TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $last = (string) end($parts);
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return substr($last, 0, 45);
            }
        }
        return $remote;
    }

    /** Never show PHP warnings, notices or paths to the browser (XAMPP's default php.ini has display_errors=On); log them instead. */
    public static function hardenRuntime(): void
    {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('html_errors', '0');
        ini_set('log_errors', '1');
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            ini_set('error_log', $dir . '/php-error.log');
        }
        error_reporting(E_ALL);
    }

    public static function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return Env::get('TRUST_PROXY') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** Production must be served over HTTPS; plain-HTTP requests are redirected to APP_URL. */
    public static function enforceHttps(): void
    {
        if (!Env::isProduction() || self::isSecure()) {
            return;
        }
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        if (!str_starts_with($base, 'https://')) {
            http_response_code(500);
            echo 'Server misconfigured: APP_URL must be an https:// URL in production.';
            exit;
        }
        header('Location: ' . $base . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    public static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        if (self::isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function redirect(string $path, int $code = 302): never
    {
        header('Location: ' . $path, true, $code);
        exit;
    }

    public static function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        $defaults = [403 => 'You do not have permission to do this.', 404 => 'Page not found.', 405 => 'Method not allowed.', 429 => 'Too many requests.', 500 => 'Something went wrong.'];
        View::render('errors/error', [
            'title' => 'Error ' . $code,
            'code' => $code,
            'message' => $message !== '' ? $message : ($defaults[$code] ?? 'Error'),
        ], isset($_SESSION['uid']) ? 'app' : 'auth');
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }
}
