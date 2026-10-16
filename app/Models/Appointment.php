<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

/** Uses the existing Phase 1 tables: appointments, appointment_events, interaction_appointments. */
final class Appointment
{
    /** Creates the appointment plus its first history event. Call inside the caller's transaction. */
    public static function create(int $customerId, int $serviceId, ?string $at, string $status, int $originInteractionId, int $userId): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO appointments (customer_id, service_id, appointment_at, status, origin_interaction_id, created_by_user_id) VALUES (?,?,?,?,?,?)')
            ->execute([$customerId, $serviceId, $at, $status, $originInteractionId, $userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO appointment_events (appointment_id, from_status, to_status, interaction_id, user_id) VALUES (?, NULL, ?, ?, ?)')
            ->execute([$id, $status, $originInteractionId, $userId]);
        return $id;
    }

    public static function link(int $interactionId, int $appointmentId, string $type): void
    {
        Db::pdo()->prepare('INSERT INTO interaction_appointments (interaction_id, appointment_id, link_type) VALUES (?,?,?)')->execute([$interactionId, $appointmentId, $type]);
    }

    public static function forCustomer(int $id, int $customerId): ?array
    {
        $st = Db::pdo()->prepare('SELECT id, status FROM appointments WHERE id = ? AND customer_id = ?');
        $st->execute([$id, $customerId]);
        return $st->fetch() ?: null;
    }

    /** Minimal listing for the follow-up selector. */
    public static function listFor(int $customerId): array
    {
        $st = Db::pdo()->prepare('SELECT a.id, a.status, a.appointment_at, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.customer_id = ? ORDER BY a.id DESC LIMIT 20');
        $st->execute([$customerId]);
        return $st->fetchAll();
    }
}
