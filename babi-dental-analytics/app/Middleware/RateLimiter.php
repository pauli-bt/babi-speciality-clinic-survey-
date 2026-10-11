<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Db;
use App\Models\Setting;

/** Failed-attempt throttling backed by the login_attempts table. */
final class RateLimiter
{
    public static function windowMinutes(): int
    {
        return max(1, (int) Setting::get('login_lockout_minutes', '15'));
    }

    private static function maxAttempts(): int
    {
        return max(1, (int) Setting::get('login_max_attempts', '5'));
    }

    /** Locked when too many failures for this username since its last success, or too many from this IP. */
    public static function isLocked(string $username, string $ip): bool
    {
        $pdo = Db::pdo();
        $w = self::windowMinutes();
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0
            AND attempted_at > GREATEST(NOW() - INTERVAL ? MINUTE, COALESCE((SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND success = 1), '1970-01-01'))");
        $st->execute([$username, $w, $username]);
        if ((int) $st->fetchColumn() >= self::maxAttempts()) {
            return true;
        }
        return self::ipLocked($ip);
    }

    public static function ipLocked(string $ip): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > NOW() - INTERVAL ? MINUTE');
        $st->execute([$ip, self::windowMinutes()]);
        return (int) $st->fetchColumn() >= self::maxAttempts() * 4;
    }

    public static function record(string $username, string $ip, bool $success): void
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)')->execute([substr($username, 0, 80), $ip, $success ? 1 : 0]);
        if ($success) {
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
        }
    }
}
