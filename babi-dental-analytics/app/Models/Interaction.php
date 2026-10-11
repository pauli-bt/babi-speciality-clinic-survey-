<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Db;

final class Interaction
{
    public const REASON_LABELS = [
        'first_contact' => 'First contact (new customer)',
        'appointment_followup' => 'Existing appointment / follow-up',
        'another_service' => 'Another service',
        'campaign_response' => 'Advertisement / new campaign',
    ];

    public static function outcomeLabel(array $i): string
    {
        if ($i['channel'] === 'reception') {
            return ['used' => 'Service used', 'not_used' => 'Visit without service', 'unknown' => 'Service use not known'][$i['service_usage_outcome']] ?? '-';
        }
        return ['confirmed' => 'Appointment booked (confirmed)', 'requested' => 'Appointment requested', 'not_booked' => 'Inquiry only (no booking)', 'not_applicable' => 'Follow-up only'][$i['booking_outcome']] ?? '-';
    }

    private const SELECT = 'SELECT i.*, c.customer_code, c.name AS customer_name, c.status AS customer_status, sv.name AS service_name, camp.name AS campaign_name,
        p.name AS platform_name, src.name AS ad_source_name, r.name AS reason_name, u.name AS recorded_by_name,
        (SELECT a.id FROM appointments a WHERE a.origin_interaction_id = i.id) AS origin_appointment_id
        FROM interactions i JOIN customers c ON c.id = i.customer_id LEFT JOIN services sv ON sv.id = i.service_id LEFT JOIN campaigns camp ON camp.id = i.campaign_id
        LEFT JOIN social_platforms p ON p.id = i.social_platform_id LEFT JOIN acquisition_sources src ON src.id = i.interaction_source_id
        LEFT JOIN non_conversion_reasons r ON r.id = i.non_conversion_reason_id JOIN users u ON u.id = i.recorded_by_user_id';

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE i.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findByToken(string $token): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE i.submission_token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO interactions (customer_id, channel, interaction_reason, is_first_contact, interaction_at, social_platform_id, interaction_source_id, campaign_id, service_id,
            booking_outcome, service_usage_outcome, non_conversion_reason_id, non_conversion_other_text, notes, recorded_by_user_id, submission_token)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $d['customer_id'], $d['channel'], $d['reason'], $d['is_first_contact'], $d['interaction_at'], $d['social_platform_id'], $d['interaction_source_id'], $d['campaign_id'], $d['service_id'],
            $d['booking_outcome'], $d['service_usage_outcome'], $d['nc_reason_id'], $d['nc_other'], $d['notes'], $d['user_id'], $d['token'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{rows: array, total: int} */
    public static function list(?string $channel, int $page, int $per): array
    {
        $where = $channel !== null ? 'WHERE i.channel = ?' : '';
        $args = $channel !== null ? [$channel] : [];
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM interactions i $where");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $st = $pdo->prepare(self::SELECT . " $where ORDER BY i.interaction_at DESC, i.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) max(0, ($page - 1) * $per));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public static function linkedAppointments(int $interactionId): array
    {
        $st = Db::pdo()->prepare('SELECT a.id, a.status, a.appointment_at, s.name AS service_name, ia.link_type FROM interaction_appointments ia
            JOIN appointments a ON a.id = ia.appointment_id JOIN services s ON s.id = a.service_id WHERE ia.interaction_id = ? ORDER BY a.id');
        $st->execute([$interactionId]);
        return $st->fetchAll();
    }

    /** Only the fields staff may correct. Channel, customer, recorder, first-contact flag, reason and booking outcome are never touched. */
    public static function updateEditable(int $id, array $d): void
    {
        Db::pdo()->prepare('UPDATE interactions SET notes = ?, campaign_id = ?, service_id = ?, social_platform_id = ?, service_usage_outcome = ?, non_conversion_reason_id = ?, non_conversion_other_text = ? WHERE id = ?')
            ->execute([$d['notes'], $d['campaign_id'], $d['service_id'], $d['social_platform_id'], $d['service_usage_outcome'], $d['nc_reason_id'], $d['nc_other'], $id]);
    }
}
