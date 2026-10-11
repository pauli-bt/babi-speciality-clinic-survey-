<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Db;
use App\Helpers\Http;

final class Audit
{
    /** Writes one audit row. Secrets (password, hash, token) are redacted from the payloads. */
    public static function log(?int $userId, string $action, ?string $entityType = null, int|string|null $entityId = null, ?array $old = null, ?array $new = null): void
    {
        $st = Db::pdo()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $userId,
            $action,
            $entityType,
            $entityId === null ? null : (string) $entityId,
            $old === null ? null : json_encode(self::redact($old), JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode(self::redact($new), JSON_UNESCAPED_UNICODE),
            Http::ip(),
        ]);
    }

    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (preg_match('/password|hash|token|secret/i', (string) $k)) {
                $data[$k] = '[redacted]';
            }
        }
        return $data;
    }
}
