<?php
declare(strict_types=1);

namespace App\Helpers;

use PDO;

/**
 * PDO factory. The runtime connection uses a least-privilege account (DML only).
 * Migrations may use DB_MIGRATE_USER / DB_MIGRATE_PASS (DDL rights) when set.
 */
final class Db
{
    private static array $conn = [];

    public static function pdo(bool $migrate = false): PDO
    {
        $key = $migrate ? 'migrate' : 'app';
        if (isset(self::$conn[$key])) {
            return self::$conn[$key];
        }
        $user = $migrate ? (Env::get('DB_MIGRATE_USER') ?? Env::get('DB_USER')) : Env::get('DB_USER');
        $pass = $migrate ? (Env::get('DB_MIGRATE_PASS') ?? Env::get('DB_PASS')) : Env::get('DB_PASS');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', 'localhost'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME', 'babi_dental')
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Keep NOW()/TIMESTAMPDIFF in the same clinic-local clock PHP uses.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Clock::mysqlOffset() . "'",
        ] + self::tlsOptions();
        return self::$conn[$key] = new PDO($dsn, (string) $user, (string) $pass, $options);
    }

    /**
     * Optional encrypted connection for managed databases that refuse plain connections (Aiven, PlanetScale, most cloud MySQL).
     * DB_SSL=1            -> use TLS. Without DB_SSL_CA the traffic is encrypted but the server is not verified.
     * DB_SSL_CA=/path.pem -> also verify the server certificate against this CA file (recommended).
     * Unset or 0 (default) -> exactly the previous behaviour (local XAMPP, same-machine database).
     * @return array<int, mixed>
     */
    private static function tlsOptions(): array
    {
        if (!in_array(strtolower(trim((string) Env::get('DB_SSL', '0'))), ['1', 'true', 'yes', 'on'], true)) {
            return [];
        }
        $ca = trim((string) Env::get('DB_SSL_CA', ''));
        if ($ca !== '' && is_readable($ca)) {
            // Encrypted and the server certificate is verified against the CA file (Aiven: download "CA certificate" from the service page).
            return [
                PDO::MYSQL_ATTR_SSL_CA => $ca,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ];
        }
        if ($ca !== '') {
            error_log('[babi] DB_SSL_CA is set but the file is not readable: ' . $ca . ' (falling back to encryption without certificate verification)');
        }
        // Encrypted but not verified: the system CA bundle only switches TLS on, verification is turned off.
        foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $bundle) {
            if (is_readable($bundle)) {
                return [
                    PDO::MYSQL_ATTR_SSL_CA => $bundle,
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                ];
            }
        }
        return [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false];
    }
}
