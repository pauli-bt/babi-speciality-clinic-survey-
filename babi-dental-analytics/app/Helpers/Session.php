<?php
declare(strict_types=1);

namespace App\Helpers;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = Http::isSecure() || Env::isProduction();
        $path = Env::get('SESSION_PATH', BASE_PATH . '/storage/sessions');
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.gc_maxlifetime', '28800');
        session_save_path($path);
        // __Host- prefix requires Secure, Path=/ and no Domain: it pins the cookie to this origin.
        session_name($secure ? '__Host-babi_sid' : 'babi_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}
