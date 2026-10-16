<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

final class Customer
{
    private const SELECT = 'SELECT c.*, s.name AS source_name, s.category AS source_category, p.name AS platform_name, camp.name AS campaign_name, u.name AS created_by_name
        FROM customers c
        JOIN acquisition_sources s ON s.id = c.original_source_id
        LEFT JOIN social_platforms p ON p.id = c.original_social_platform_id
        LEFT JOIN campaigns camp ON camp.id = c.original_campaign_id
        JOIN users u ON u.id = c.created_by_user_id';

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findByToken(string $token): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE c.submission_token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    /** Atomic, gap-tolerant sequence; call inside the registration transaction. */
    public static function nextCode(): string
    {
        $pdo = Db::pdo();
        $pdo->exec('UPDATE customer_code_counter SET next_value = LAST_INSERT_ID(next_value + 1) WHERE id = 1');
        return sprintf('BDC-%06d', (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn());
    }

    public static function insert(array $d): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO customers (customer_code, submission_token, name, phone, phone_normalized, original_source_id, original_social_platform_id, original_source_other, original_campaign_id, first_acquisition_channel, first_acquisition_at, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), ?)')->execute([
            $d['code'], $d['token'], $d['name'], $d['phone'], $d['phone_normalized'], $d['source_id'], $d['platform_id'], $d['source_other'], $d['campaign_id'], $d['channel'], $d['acquired_at'] ?? null, $d['user_id'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Active customers that share the phone number or the exact name (case-insensitive). */
    public static function findDuplicates(?string $name, ?string $phoneNormalized): array
    {
        if (($name === null || $name === '') && ($phoneNormalized === null || $phoneNormalized === '')) {
            return [];
        }
        $st = Db::pdo()->prepare(self::SELECT . " WHERE c.status = 'active' AND ((? <> '' AND c.phone_normalized = ?) OR (? <> '' AND c.name = ?)) ORDER BY c.first_acquisition_at DESC LIMIT 10");
        $st->execute([$phoneNormalized ?? '', $phoneNormalized ?? '', $name ?? '', $name ?? '']);
        return $st->fetchAll();
    }

    /**
     * @param array{kind:string,tokens?:array,digits?:string,code?:string} $q
     * @return array{rows: array, total: int}
     */
    public static function search(array $q, bool $includeArchived, int $page, int $perPage): array
    {
        $where = [];
        $args = [];
        if ($q['kind'] === 'code') {
            $where[] = 'c.customer_code = ?';
            $args[] = $q['code'];
        } elseif ($q['kind'] === 'phone') {
            $where[] = 'c.phone_normalized LIKE ?';
            $args[] = '%' . $q['digits'] . '%';
        } else {
            foreach ($q['tokens'] as $tok) {
                $where[] = "c.name LIKE ? ESCAPE '\\\\'";
                $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tok) . '%';
            }
        }
        if (!$includeArchived) {
            $where[] = "c.status = 'active'";
        }
        $w = implode(' AND ', $where);
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $w");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);
        $st = $pdo->prepare('SELECT c.id, c.customer_code, c.name, c.phone_normalized, c.status, c.first_acquisition_at, s.name AS source_name,
            (SELECT COUNT(*) FROM customers o WHERE o.status = \'active\' AND o.id <> c.id AND c.phone_normalized IS NOT NULL AND o.phone_normalized = c.phone_normalized) AS shared_phone_count
            FROM customers c JOIN acquisition_sources s ON s.id = c.original_source_id WHERE ' . $w . ' ORDER BY c.name IS NULL, c.name, c.id LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset);
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public static function updateContact(int $id, ?string $name, ?string $phone, ?string $phoneNormalized): void
    {
        Db::pdo()->prepare('UPDATE customers SET name = ?, phone = ?, phone_normalized = ? WHERE id = ?')->execute([$name, $phone, $phoneNormalized, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Db::pdo()->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** Interactions visible to the caller; $channel limits entry staff to their own channel. */
    public static function interactions(int $customerId, ?string $channel): array
    {
        $sql = 'SELECT i.id, i.channel, i.interaction_reason, i.interaction_at, i.booking_outcome, i.service_usage_outcome, sv.name AS service_name
            FROM interactions i LEFT JOIN services sv ON sv.id = i.service_id WHERE i.customer_id = ?';
        $args = [$customerId];
        if ($channel !== null) {
            $sql .= ' AND i.channel = ?';
            $args[] = $channel;
        }
        $st = Db::pdo()->prepare($sql . ' ORDER BY i.interaction_at DESC, i.id DESC LIMIT 30');
        $st->execute($args);
        return $st->fetchAll();
    }
}
