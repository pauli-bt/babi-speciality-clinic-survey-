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
        return self::$conn[$key] = new PDO($dsn, (string) $user, (string) $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
