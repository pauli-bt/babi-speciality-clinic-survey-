<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Http;
use App\Middleware\RateLimiter;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;

final class Auth
{
    private const BCRYPT_COST = 12;
    private static bool $loaded = false;
    private static ?array $user = null;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * Returns the signed-in user or null. Re-validated on EVERY request against the database:
     * account still active, session version unchanged (role change / reset / deactivation kill sessions),
     * idle timeout and absolute lifetime not exceeded.
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $now = time();
        $idle = (int) Setting::get('session_timeout_minutes', '30') * 60;
        $absolute = (int) Setting::get('session_absolute_hours', '8') * 3600;
        if ($now - (int) ($_SESSION['last_activity'] ?? 0) > $idle || $now - (int) ($_SESSION['created_at'] ?? 0) > $absolute) {
            self::terminate();
            Http::flash('warning', 'Your session expired. Please sign in again.');
            return null;
        }
        $u = User::findWithRole($id);
        if (!$u || (int) $u['is_active'] !== 1 || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
            self::terminate();
            return null;
        }
        $_SESSION['last_activity'] = $now;
        $u['permissions'] = Role::permissionCodes((int) $u['role_id']);
        return self::$user = $u;
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        return $u !== null && in_array($permission, $u['permissions'], true);
    }

    /** @return array{ok: bool, locked: bool, error: ?string} */
    public static function attempt(string $username, string $password): array
    {
        $username = substr(strtolower(trim($username)), 0, 80);
        $ip = Http::ip();
        $generic = 'Incorrect username or password.';

        if (RateLimiter::isLocked($username, $ip)) {
            Audit::log(null, 'auth.login_blocked');
            return ['ok' => false, 'locked' => true, 'error' => 'Too many failed attempts. Please wait about ' . RateLimiter::windowMinutes() . ' minutes and try again.'];
        }

        $user = User::findByUsername($username);
        if ($user) {
            $valid = password_verify($password, $user['password_hash']) && (int) $user['is_active'] === 1;
        } else {
            self::hash($password); // comparable work for unknown usernames (timing equalisation)
            $valid = false;
        }

        if (!$valid) {
            RateLimiter::record($username, $ip, false);
            Audit::log($user ? (int) $user['id'] : null, 'auth.login_failed', 'user', $user['id'] ?? null);
            return ['ok' => false, 'locked' => false, 'error' => $generic];
        }

        RateLimiter::record($username, $ip, true);
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            User::rehash((int) $user['id'], self::hash($password));
        }
        User::touchLogin((int) $user['id']);
        self::start((int) $user['id'], (int) $user['session_version']);
        Audit::log((int) $user['id'], 'auth.login_success', 'user', $user['id']);
        return ['ok' => true, 'locked' => false, 'error' => null];
    }

    /** Fresh session id on every privilege change defeats session fixation. */
    public static function start(int $userId, int $sessionVersion): void
    {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $userId, 'sv' => $sessionVersion, 'created_at' => time(), 'last_activity' => time()];
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) {
            Audit::log((int) $u['id'], 'auth.logout', 'user', $u['id']);
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
        self::$loaded = true;
        self::$user = null;
    }

    private static function terminate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }
}
