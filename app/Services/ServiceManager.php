<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Db;

/** Clinic service catalogue. Services are never deleted: deactivating hides them from new entries only. */
final class ServiceManager
{
    public static function all(): array
    {
        return Db::pdo()->query('SELECT s.*,
            (SELECT COUNT(*) FROM interactions i WHERE i.service_id = s.id) AS interaction_count,
            (SELECT COUNT(*) FROM appointments a WHERE a.service_id = s.id) AS appointment_count
            FROM services s ORDER BY s.is_active DESC, s.name')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM services WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return string[] */
    private static function validate(array $in, ?int $exceptId): array
    {
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $e[] = 'Enter a service name of up to 120 characters.';
        } else {
            $st = Db::pdo()->prepare('SELECT id FROM services WHERE name = ? AND id <> ?');
            $st->execute([$name, $exceptId ?? 0]);
            if ($st->fetch()) {
                $e[] = 'A service with this name already exists.';
            }
        }
        if (mb_strlen($desc) > 255) {
            $e[] = 'The description can be at most 255 characters.';
        }
        return $e;
    }

    /** @return array{ok: bool, errors: string[], id?: int} */
    public static function create(array $actor, array $in): array
    {
        $e = self::validate($in, null);
        if ($e) {
            return ['ok' => false, 'errors' => $e];
        }
        $name = trim((string) $in['name']);
        $desc = trim((string) ($in['description'] ?? ''));
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO services (name, description, is_active) VALUES (?, ?, 1)')->execute([$name, $desc === '' ? null : $desc]);
        $id = (int) $pdo->lastInsertId();
        Audit::log((int) $actor['id'], 'service.create', 'service', $id, null, ['name' => $name, 'description' => $desc === '' ? null : $desc, 'is_active' => 1]);
        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function update(array $actor, array $service, array $in): array
    {
        $e = self::validate($in, (int) $service['id']);
        if ($e) {
            return ['ok' => false, 'errors' => $e];
        }
        $name = trim((string) $in['name']);
        $desc = trim((string) ($in['description'] ?? ''));
        $desc = $desc === '' ? null : $desc;
        if ($name !== $service['name'] || $desc !== $service['description']) {
            Db::pdo()->prepare('UPDATE services SET name = ?, description = ? WHERE id = ?')->execute([$name, $desc, $service['id']]);
            Audit::log((int) $actor['id'], 'service.update', 'service', (int) $service['id'],
                ['name' => $service['name'], 'description' => $service['description']], ['name' => $name, 'description' => $desc]);
        }
        return ['ok' => true, 'errors' => []];
    }

    public static function setActive(array $actor, array $service, bool $active): void
    {
        if ((int) $service['is_active'] === ($active ? 1 : 0)) {
            return;
        }
        Db::pdo()->prepare('UPDATE services SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $service['id']]);
        Audit::log((int) $actor['id'], $active ? 'service.activate' : 'service.deactivate', 'service', (int) $service['id'], ['is_active' => (int) $service['is_active']], ['is_active' => $active ? 1 : 0]);
    }
}
