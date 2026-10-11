<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

final class Setting
{
    private static ?array $cache = null;

    public static function get(string $key, string $default): string
    {
        if (self::$cache === null) {
            self::$cache = Db::pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        return self::$cache[$key] ?? $default;
    }
}
