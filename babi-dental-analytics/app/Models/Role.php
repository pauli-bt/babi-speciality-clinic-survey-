<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

final class Role
{
    public static function all(): array
    {
        return Db::pdo()->query('SELECT id, code, name FROM roles ORDER BY id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT id, code, name FROM roles WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return string[] */
    public static function permissionCodes(int $roleId): array
    {
        $st = Db::pdo()->prepare('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
        $st->execute([$roleId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }
}
