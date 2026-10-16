<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\{Clock, Db};

/**
 * Marketing campaigns. Never deleted. Nothing here touches customers: a customer's original acquisition source
 * is write-once, and a campaign response is only ever stored on an interaction (re-engagement).
 */
final class CampaignManager
{
    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended'];

    public static function all(?string $status = null): array
    {
        $sql = 'SELECT c.*, p.name AS platform_name,
            (SELECT COUNT(*) FROM interactions i WHERE i.campaign_id = c.id) AS response_count
            FROM campaigns c LEFT JOIN social_platforms p ON p.id = c.platform_id';
        $args = [];
        if ($status !== null && isset(self::STATUSES[$status])) {
            $sql .= ' WHERE c.status = ?';
            $args[] = $status;
        }
        $st = Db::pdo()->prepare($sql . " ORDER BY FIELD(c.status,'active','paused','draft','ended'), c.start_date DESC, c.name");
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT c.*, p.name AS platform_name,
            (SELECT COUNT(*) FROM interactions i WHERE i.campaign_id = c.id) AS response_count
            FROM campaigns c LEFT JOIN social_platforms p ON p.id = c.platform_id WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array{errors: string[], data: array} */
    private static function validate(array $in, ?array $existing): array
    {
        $e = [];
        $pdo = Db::pdo();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $e[] = 'Enter a campaign name of up to 150 characters.';
        }
        $code = trim((string) ($in['campaign_code'] ?? ''));
        if ($code !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{1,60}$/', $code)) {
                $e[] = 'The campaign code may use letters, numbers, dot, dash and underscore (up to 60).';
            } else {
                $st = $pdo->prepare('SELECT id FROM campaigns WHERE campaign_code = ? AND id <> ?');
                $st->execute([$code, $existing['id'] ?? 0]);
                if ($st->fetch()) {
                    $e[] = 'This campaign code is already used.';
                }
            }
        }
        $platformRaw = trim((string) ($in['platform_id'] ?? ''));
        $platform = null;
        if ($platformRaw !== '') {
            if (!ctype_digit($platformRaw)) {
                $e[] = 'Choose a valid platform.';
            } else {
                $platform = (int) $platformRaw;
                $st = $pdo->prepare('SELECT id FROM social_platforms WHERE id = ? AND (is_active = 1 OR id = ?)');
                $st->execute([$platform, $existing['platform_id'] ?? 0]);
                if (!$st->fetch()) {
                    $e[] = 'Choose a valid platform.';
                }
            }
        }
        $dates = [];
        foreach (['start_date' => 'start', 'end_date' => 'end'] as $k => $label) {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') {
                $dates[$k] = null;
            } elseif (!($d = Clock::parseDate($raw))) {
                $e[] = "Enter a valid $label date.";
                $dates[$k] = null;
            } else {
                $dates[$k] = $d->format('Y-m-d');
            }
        }
        if ($dates['start_date'] && $dates['end_date'] && $dates['end_date'] < $dates['start_date']) {
            $e[] = 'The end date cannot be before the start date.';
        }
        $status = (string) ($in['status'] ?? 'draft');
        if (!isset(self::STATUSES[$status])) {
            $e[] = 'Choose a valid status.';
        } elseif ($status === 'draft' && $existing && (int) $existing['response_count'] > 0) {
            $e[] = 'A campaign that already has recorded responses cannot go back to draft. Use paused or ended.';
        }
        return ['errors' => $e, 'data' => ['name' => $name, 'campaign_code' => $code === '' ? null : $code, 'platform_id' => $platform,
            'start_date' => $dates['start_date'], 'end_date' => $dates['end_date'], 'status' => $status]];
    }

    /** @return array{ok: bool, errors: string[], id?: int} */
    public static function create(array $actor, array $in): array
    {
        $v = self::validate($in, null);
        if ($v['errors']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $d = $v['data'];
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO campaigns (name, platform_id, campaign_code, start_date, end_date, status) VALUES (?,?,?,?,?,?)')
            ->execute([$d['name'], $d['platform_id'], $d['campaign_code'], $d['start_date'], $d['end_date'], $d['status']]);
        $id = (int) $pdo->lastInsertId();
        Audit::log((int) $actor['id'], 'campaign.create', 'campaign', $id, null, $d);
        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function update(array $actor, array $campaign, array $in): array
    {
        $v = self::validate($in, $campaign);
        if ($v['errors']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $d = $v['data'];
        $old = array_intersect_key($campaign, $d);
        if ($old !== $d && array_map('strval', $old) !== array_map('strval', $d)) {
            Db::pdo()->prepare('UPDATE campaigns SET name=?, platform_id=?, campaign_code=?, start_date=?, end_date=?, status=? WHERE id=?')
                ->execute([$d['name'], $d['platform_id'], $d['campaign_code'], $d['start_date'], $d['end_date'], $d['status'], $campaign['id']]);
            Audit::log((int) $actor['id'], $old['status'] !== $d['status'] ? 'campaign.status_change' : 'campaign.update', 'campaign', (int) $campaign['id'], $old, $d);
        }
        return ['ok' => true, 'errors' => []];
    }
}
