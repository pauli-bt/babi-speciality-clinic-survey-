<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\{Clock, Db};
use Throwable;

/**
 * Appointment viewing and status management. Appointments are CREATED only by the phone / DM questionnaires
 * (one booking event, counted once). Here they are listed, moved through their lifecycle and rescheduled.
 * Every status change writes appointment_events in the same transaction; reschedules go to the audit log only,
 * so the status history (used for counting) is never inflated.
 */
final class AppointmentManager
{
    public const STATUS_LABELS = ['requested' => 'Requested', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];
    public const TRANSITIONS = [
        'requested' => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled', 'no_show'],
        'completed' => [], 'cancelled' => [], 'no_show' => [],
    ];
    public const PAGE_SIZE = 20;

    private const BASE = 'FROM appointments a
        JOIN customers c ON c.id = a.customer_id
        JOIN services s ON s.id = a.service_id
        LEFT JOIN interactions oi ON oi.id = a.origin_interaction_id';

    /** @return array{rows: array, total: int, page: int, pages: int, filters: array, errors: string[]} */
    public static function search(array $q, bool $canSeeNames): array
    {
        $where = []; $args = []; $errors = [];
        $f = ['status' => '', 'service_id' => '', 'channel' => '', 'from' => '', 'to' => '', 'q' => trim((string) ($q['q'] ?? ''))];
        $status = (string) ($q['status'] ?? '');
        if ($status !== '') {
            if (isset(self::STATUS_LABELS[$status])) { $where[] = 'a.status = ?'; $args[] = $f['status'] = $status; } else { $errors[] = 'Unknown status filter.'; }
        }
        $sid = (string) ($q['service_id'] ?? '');
        if ($sid !== '') {
            if (ctype_digit($sid)) { $where[] = 'a.service_id = ?'; $args[] = (int) $sid; $f['service_id'] = $sid; } else { $errors[] = 'Unknown service filter.'; }
        }
        $ch = (string) ($q['channel'] ?? '');
        if ($ch !== '') {
            if (in_array($ch, ['phone', 'social_dm', 'reception'], true)) { $where[] = 'oi.channel = ?'; $args[] = $f['channel'] = $ch; } else { $errors[] = 'Unknown channel filter.'; }
        }
        if (($from = trim((string) ($q['from'] ?? ''))) !== '') {
            if ($d = Clock::parseDate($from)) { $where[] = 'a.appointment_at >= ?'; $args[] = $d->format('Y-m-d 00:00:00'); $f['from'] = $from; } else { $errors[] = 'Enter a valid "from" date.'; }
        }
        if (($to = trim((string) ($q['to'] ?? ''))) !== '') {
            if ($d = Clock::parseDate($to)) { $where[] = 'a.appointment_at < ?'; $args[] = $d->modify('+1 day')->format('Y-m-d 00:00:00'); $f['to'] = $to; } else { $errors[] = 'Enter a valid "to" date.'; }
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            if ($canSeeNames) { $where[] = '(c.customer_code LIKE ? OR c.name LIKE ?)'; array_push($args, $like, $like); }
            else { $where[] = 'c.customer_code LIKE ?'; $args[] = $like; }
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) ' . self::BASE . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) ($q['page'] ?? 1)));
        $st = $pdo->prepare('SELECT a.id, a.status, a.appointment_at, a.created_at, a.customer_id, c.customer_code, c.name AS customer_name, s.name AS service_name, oi.channel AS origin_channel '
            . self::BASE . $w . ' ORDER BY COALESCE(a.appointment_at, a.created_at) DESC, a.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'filters' => $f, 'errors' => $errors];
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT a.*, c.customer_code, c.name AS customer_name, s.name AS service_name, s.is_active AS service_active, oi.channel AS origin_channel, u.name AS created_by_name '
            . self::BASE . ' JOIN users u ON u.id = a.created_by_user_id WHERE a.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function history(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT e.id, e.from_status, e.to_status, e.interaction_id, e.event_at, u.name AS user_name FROM appointment_events e JOIN users u ON u.id = e.user_id WHERE e.appointment_id = ? ORDER BY e.event_at, e.id');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** Reschedules from the audit log (no status change, so not part of appointment_events). */
    public static function reschedules(int $id): array
    {
        $st = Db::pdo()->prepare("SELECT l.created_at, l.old_values, l.new_values, u.name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.entity_type = 'appointment' AND l.entity_id = ? AND l.action = 'appointment.reschedule' ORDER BY l.id");
        $st->execute([(string) $id]);
        return array_map(static function (array $r): array {
            $o = json_decode((string) $r['old_values'], true) ?: []; $n = json_decode((string) $r['new_values'], true) ?: [];
            return ['at' => $r['created_at'], 'user' => $r['user_name'], 'from' => $o['appointment_at'] ?? null, 'to' => $n['appointment_at'] ?? null];
        }, $st->fetchAll());
    }

    /** Linked interactions: ids, channel, date and link type only (no notes or customer details). */
    public static function links(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT ia.interaction_id, ia.link_type, i.channel, i.interaction_at FROM interaction_appointments ia JOIN interactions i ON i.id = ia.interaction_id WHERE ia.appointment_id = ? ORDER BY i.interaction_at, ia.interaction_id');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function changeStatus(array $actor, int $id, string $to, string $rawAt = ''): array
    {
        if (!isset(self::STATUS_LABELS[$to])) {
            return ['ok' => false, 'errors' => ['Choose a valid status.']];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $a = $st->fetch();
            if (!$a) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['Appointment not found.']];
            }
            $from = (string) $a['status'];
            if (!in_array($to, self::TRANSITIONS[$from], true)) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => [self::STATUS_LABELS[$from] . ' appointments cannot be changed to ' . strtolower(self::STATUS_LABELS[$to]) . '.']];
            }
            $now = Clock::now();
            $at = $a['appointment_at'];
            $errors = [];
            if ($to === 'confirmed') {
                if (trim($rawAt) !== '') {
                    $p = Clock::parseLocal($rawAt);
                    if (!$p) { $errors[] = 'Enter a valid appointment date and time.'; }
                    elseif ($p < $now->modify('-5 minutes')) { $errors[] = 'The appointment time cannot be in the past.'; }
                    elseif ($p > $now->modify('+365 days')) { $errors[] = 'The appointment cannot be more than one year ahead.'; }
                    else { $at = $p->format('Y-m-d H:i:s'); }
                } elseif ($at === null) {
                    $errors[] = 'A confirmed appointment needs a date and time.';
                }
            } elseif ($to === 'completed' || $to === 'no_show') {
                if ($at === null) { $errors[] = 'Set the appointment date and time before closing it.'; }
                elseif (new \DateTimeImmutable((string) $at, Clock::zone()) > $now) { $errors[] = 'An appointment that has not started yet cannot be marked ' . strtolower(self::STATUS_LABELS[$to]) . '.'; }
            }
            if ($errors) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => $errors];
            }
            $u = $pdo->prepare('UPDATE appointments SET status = ?, appointment_at = ? WHERE id = ? AND status = ?');
            $u->execute([$to, $at, $id, $from]);
            if ($u->rowCount() !== 1) {
                throw new \RuntimeException('Appointment changed concurrently.');
            }
            $pdo->prepare('INSERT INTO appointment_events (appointment_id, from_status, to_status, interaction_id, user_id) VALUES (?,?,?,NULL,?)')->execute([$id, $from, $to, (int) $actor['id']]);
            Audit::log((int) $actor['id'], 'appointment.status_change', 'appointment', $id,
                ['status' => $from, 'appointment_at' => $a['appointment_at']], ['status' => $to, 'appointment_at' => $at]);
            $pdo->commit();
            return ['ok' => true, 'errors' => []];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[babi] appointment status change failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['The appointment was changed by someone else or could not be saved. Reload and try again.']];
        }
    }

    /** Only requested/confirmed appointments can move. No status event is written (see class note). */
    public static function reschedule(array $actor, int $id, string $rawAt): array
    {
        $p = Clock::parseLocal($rawAt);
        $now = Clock::now();
        if (!$p) { return ['ok' => false, 'errors' => ['Enter a valid appointment date and time.']]; }
        if ($p < $now->modify('-5 minutes')) { return ['ok' => false, 'errors' => ['The new time cannot be in the past.']]; }
        if ($p > $now->modify('+365 days')) { return ['ok' => false, 'errors' => ['The appointment cannot be more than one year ahead.']]; }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $a = $st->fetch();
            if (!$a || !in_array($a['status'], ['requested', 'confirmed'], true)) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['Only requested or confirmed appointments can be rescheduled.']];
            }
            $new = $p->format('Y-m-d H:i:s');
            if ($new !== $a['appointment_at']) {
                $pdo->prepare('UPDATE appointments SET appointment_at = ? WHERE id = ?')->execute([$new, $id]);
                Audit::log((int) $actor['id'], 'appointment.reschedule', 'appointment', $id, ['appointment_at' => $a['appointment_at']], ['appointment_at' => $new]);
            }
            $pdo->commit();
            return ['ok' => true, 'errors' => []];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[babi] appointment reschedule failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['The appointment could not be rescheduled. Try again.']];
        }
    }
}
