<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

final class User
{
    private const SELECT = 'SELECT u.*, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id';

    public static function findWithRole(int $id): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE u.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE u.username = ?');
        $st->execute([$username]);
        return $st->fetch() ?: null;
    }

    public static function all(): array
    {
        return Db::pdo()->query(self::SELECT . ' ORDER BY u.is_active DESC, u.name')->fetchAll();
    }

    public static function countByRole(): array
    {
        return Db::pdo()->query('SELECT r.name, SUM(u.is_active = 1) AS active, SUM(u.is_active = 0) AS inactive FROM roles r LEFT JOIN users u ON u.role_id = r.id GROUP BY r.id, r.name ORDER BY r.id')->fetchAll();
    }

    public static function countActiveAdmins(?int $excludeId = null): int
    {
        $st = Db::pdo()->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1 AND u.id <> ?");
        $st->execute([$excludeId ?? 0]);
        return (int) $st->fetchColumn();
    }

    public static function usernameTaken(string $username, ?int $excludeId = null): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
        $st->execute([$username, $excludeId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function emailTaken(string $email, ?int $excludeId = null): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $st->execute([$email, $excludeId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function insert(string $name, string $username, ?string $email, string $hash, int $roleId, bool $mustChange, ?int $createdBy): int
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role_id, must_change_password, password_changed_at, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$name, $username, $email, $hash, $roleId, $mustChange ? 1 : 0, $mustChange ? null : date('Y-m-d H:i:s'), $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateProfile(int $id, string $name, ?string $email, int $roleId): void
    {
        Db::pdo()->prepare('UPDATE users SET name = ?, email = ?, role_id = ? WHERE id = ?')->execute([$name, $email, $roleId, $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::pdo()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    /** Invalidates every existing session of this user. */
    public static function bumpSessionVersion(int $id): void
    {
        Db::pdo()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$id]);
    }

    public static function setPassword(int $id, string $hash, bool $mustChange): void
    {
        Db::pdo()->prepare('UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?')
            ->execute([$hash, $mustChange ? 1 : 0, $id]);
    }

    public static function rehash(int $id, string $hash): void
    {
        Db::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public static function touchLogin(int $id): void
    {
        Db::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
    }
}
