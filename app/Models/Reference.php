<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

/** Admin-configurable lookup lists. */
final class Reference
{
    public static function sources(bool $activeOnly = true): array
    {
        return Db::pdo()->query('SELECT * FROM acquisition_sources ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY sort_order, name')->fetchAll();
    }

    public static function source(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM acquisition_sources WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function platforms(): array
    {
        return Db::pdo()->query('SELECT * FROM social_platforms WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
    }

    public static function platform(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM social_platforms WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Campaigns staff may pick (draft campaigns are not yet live). */
    public static function campaigns(): array
    {
        return Db::pdo()->query("SELECT id, name FROM campaigns WHERE status <> 'draft' ORDER BY name")->fetchAll();
    }

    public static function campaign(int $id): ?array
    {
        $st = Db::pdo()->prepare("SELECT * FROM campaigns WHERE id = ? AND status <> 'draft'");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function services(): array
    {
        return Db::pdo()->query('SELECT id, name FROM services WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public static function service(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT id, name FROM services WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Reasons a customer did not convert; $scope is 'service_usage' (reception) or 'booking' (phone / DM). */
    public static function reasons(string $scope): array
    {
        $st = Db::pdo()->prepare("SELECT id, name, allows_other_text FROM non_conversion_reasons WHERE is_active = 1 AND applies_to IN (?, 'both') ORDER BY sort_order, name");
        $st->execute([$scope]);
        return $st->fetchAll();
    }

    public static function reason(int $id, string $scope): ?array
    {
        $st = Db::pdo()->prepare("SELECT id, name, allows_other_text FROM non_conversion_reasons WHERE id = ? AND is_active = 1 AND applies_to IN (?, 'both')");
        $st->execute([$id, $scope]);
        return $st->fetch() ?: null;
    }
}
