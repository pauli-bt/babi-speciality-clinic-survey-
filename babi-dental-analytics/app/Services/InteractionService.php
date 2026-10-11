<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\{Channel, Db};
use App\Models\{Appointment, Customer, Interaction, Reference, Setting};
use App\Validation\CustomerValidator;
use DateTimeImmutable;

/**
 * One pipeline for all three channels. The channel always comes from the signed-in user's role, never from the form.
 * Customer (optional) + interaction + appointment (optional) + audit rows are written in ONE transaction.
 */
final class InteractionService
{
    private const RETURNING = ['appointment_followup', 'another_service', 'campaign_response'];
    /** Fields that belong to another channel. Posting them is rejected, not silently ignored. */
    private const FORBIDDEN = [
        'reception' => ['booking', 'appointment_at', 'dm_platform_id'],
        'phone' => ['used', 'dm_platform_id'],
        'social_dm' => ['used'],
    ];

    // ------------------------------------------------------------------ helpers
    private static function parseTime(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            $e = DateTimeImmutable::getLastErrors();
            if ($d && (!$e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) {
                return $d;
            }
        }
        return null;
    }

    /** Positive integer id from a posted value; null when empty; false when malformed. */
    private static function id(mixed $raw): int|null|false
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        return preg_match('/^[1-9]\d{0,9}$/', $raw) ? (int) $raw : false;
    }

    private static function notes(mixed $raw, array &$errors): ?string
    {
        $n = trim(str_replace("\r\n", "\n", (string) $raw));
        $n = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $n) ?? '';
        if (mb_strlen($n) > 1000) {
            $errors[] = 'Notes must be at most 1000 characters.';
        }
        return $n === '' ? null : $n;
    }

    /** Non-conversion reason (why no booking / no service use). */
    private static function reasonOf(array $in, string $scope, bool $required, array &$errors, string $what): array
    {
        $rid = self::id($in['non_conversion_reason_id'] ?? '');
        $out = ['id' => null, 'other' => null];
        if ($rid === false || ($rid !== null && !($r = Reference::reason($rid, $scope)))) {
            $errors[] = 'Choose a valid reason.';
        } elseif ($rid === null) {
            if ($required) {
                $errors[] = "Choose why $what.";
            }
        } else {
            $out['id'] = $rid;
            $other = trim((string) ($in['non_conversion_other_text'] ?? ''));
            if ((int) $r['allows_other_text'] === 1 && $other !== '') {
                if (mb_strlen($other) > 300) {
                    $errors[] = 'The explanation must be at most 300 characters.';
                }
                $out['other'] = $other;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ validation shared by create()
    /** @return array{errors: string[], data: array} */
    private static function validate(string $channel, string $mode, ?int $customerId, array $in, ?int $newCampaignId): array
    {
        $e = [];
        $d = ['service_id' => null, 'campaign_id' => null, 'social_platform_id' => null, 'interaction_source_id' => null, 'booking_outcome' => 'not_applicable',
            'service_usage_outcome' => 'not_applicable', 'nc_reason_id' => null, 'nc_other' => null, 'appointment' => null, 'followup_appointment_id' => null];
        $label = Channel::LABELS[$channel];

        foreach (self::FORBIDDEN[$channel] as $f) {
            if (trim((string) ($in[$f] ?? '')) !== '') {
                $e[] = "The field \"$f\" does not belong to $label interactions.";
            }
        }

        // date/time
        $at = self::parseTime((string) ($in['interaction_at'] ?? ''));
        $now = new DateTimeImmutable('now');
        $max = max(1, (int) Setting::get('interaction_max_backdate_days', '30'));
        if (!$at) {
            $e[] = 'Enter a valid interaction date and time.';
        } elseif ($at > $now->modify('+5 minutes')) {
            $e[] = 'The interaction time cannot be in the future.';
        } elseif ($at < $now->modify("-$max days")) {
            $e[] = "The interaction cannot be older than $max days.";
        }
        $d['interaction_at'] = $at ? $at->format('Y-m-d H:i:s') : null;

        // reason
        $reason = 'first_contact';
        if ($mode === 'existing') {
            $reason = (string) ($in['reason'] ?? '');
            if (!in_array($reason, self::RETURNING, true)) {
                $e[] = 'Choose the reason for this interaction.';
            }
        }
        $d['reason'] = $reason;
        $followup = $reason === 'appointment_followup';

        // service of interest / used / booked
        $sid = self::id($in['service_id'] ?? '');
        $service = null;
        if ($sid === false || ($sid !== null && !($service = Reference::service($sid)))) {
            $e[] = 'Choose a valid service.';
        }
        $d['service_id'] = $service ? (int) $service['id'] : null;

        // campaign: optional, never automatic
        if ($mode === 'new') {
            $d['campaign_id'] = $newCampaignId;
        } else {
            $cid = self::id($in['interaction_campaign_id'] ?? '');
            if ($cid === false || ($cid !== null && !Reference::campaign($cid))) {
                $e[] = 'That campaign is not available.';
            } else {
                $d['campaign_id'] = $cid;
            }
        }

        // follow-up appointment link
        $fid = self::id($in['followup_appointment_id'] ?? '');
        if ($fid === false) {
            $e[] = 'Choose a valid appointment.';
        } elseif ($fid !== null) {
            if (!$followup || $customerId === null) {
                $e[] = 'An existing appointment can only be linked to a follow-up for an existing customer.';
            } elseif (!Appointment::forCustomer($fid, $customerId)) {
                $e[] = 'That appointment does not belong to this customer.';
            } else {
                $d['followup_appointment_id'] = $fid;
            }
        }

        // where the customer saw the advertisement (reception / phone); for DMs the DM platform is the platform
        if ($reason === 'campaign_response' && $channel !== 'social_dm') {
            $srcId = self::id($in['ad_source_id'] ?? '');
            $src = ($srcId !== null && $srcId !== false) ? Reference::source($srcId) : null;
            if (!$src || (int) $src['is_active'] !== 1 || $src['category'] === 'unknown') {
                $e[] = 'Say where the customer saw the advertisement.';
            } elseif ((int) $src['requires_platform'] === 1) {
                $pid = self::id($in['ad_platform_id'] ?? '');
                $pf = ($pid !== null && $pid !== false) ? Reference::platform($pid) : null;
                if (!$pf) {
                    $e[] = 'Choose the social media platform where the advertisement was seen.';
                } else {
                    $d['social_platform_id'] = (int) $pf['id'];
                }
            } else {
                $d['interaction_source_id'] = (int) $src['id'];
            }
        }

        // channel-specific outcome
        if ($channel === 'social_dm') {
            $pid = self::id($in['dm_platform_id'] ?? '');
            $pf = ($pid !== null && $pid !== false) ? Reference::platform($pid) : null;
            if (!$pf) {
                $e[] = 'Choose the social media platform used for this conversation.';
            } else {
                $d['social_platform_id'] = (int) $pf['id'];
            }
        }

        if ($channel === 'reception') {
            $used = (string) ($in['used'] ?? '');
            $allowed = $followup ? ['used', 'not_used', 'unknown'] : ['used', 'not_used'];
            if (!in_array($used, $allowed, true)) {
                $e[] = 'Say whether the customer used the service.';
            } else {
                $d['service_usage_outcome'] = $used;
                if ($used === 'used' && !$service) {
                    $e[] = 'Choose the service the customer used.';
                }
                if ($used === 'not_used') {
                    $r = self::reasonOf($in, 'service_usage', !$followup, $e, 'the customer did not use the service');
                    $d['nc_reason_id'] = $r['id'];
                    $d['nc_other'] = $r['other'];
                }
            }
        } else {
            $b = (string) ($in['booking'] ?? '');
            $allowed = $followup ? ['confirmed', 'requested', 'not_booked', 'not_applicable'] : ['confirmed', 'requested', 'not_booked'];
            if (!in_array($b, $allowed, true)) {
                $e[] = 'Say whether an appointment was booked.';
            } else {
                $d['booking_outcome'] = $b;
                if ($b === 'confirmed' || $b === 'requested') {
                    if (!$service) {
                        $e[] = 'Choose the service for the appointment.';
                    }
                    $apt = null;
                    $rawApt = trim((string) ($in['appointment_at'] ?? ''));
                    if ($rawApt !== '') {
                        $apt = self::parseTime($rawApt);
                        if (!$apt) {
                            $e[] = 'Enter a valid appointment date and time.';
                        } elseif ($at && $apt <= $at) {
                            $e[] = 'The appointment must be after the interaction time.';
                        } elseif ($apt > $now->modify('+365 days')) {
                            $e[] = 'The appointment cannot be more than one year ahead.';
                        }
                    } elseif ($b === 'confirmed') {
                        $e[] = 'A confirmed appointment needs a date and time. Choose "requested" if the time is not agreed yet.';
                    }
                    if ($service && !array_filter($e, static fn ($m) => str_contains($m, 'appointment'))) {
                        $d['appointment'] = ['service_id' => (int) $service['id'], 'at' => $apt ? $apt->format('Y-m-d H:i:s') : null, 'status' => $b];
                    }
                } elseif ($b === 'not_booked') {
                    $r = self::reasonOf($in, 'booking', !$followup, $e, 'the customer did not book');
                    $d['nc_reason_id'] = $r['id'];
                    $d['nc_other'] = $r['other'];
                }
            }
        }

        $d['notes'] = self::notes($in['notes'] ?? '', $e);
        return ['errors' => $e, 'data' => $d];
    }

    // ------------------------------------------------------------------ create
    /** @return array{ok: bool, errors: string[], duplicates: array, id?: int, customer_code?: string, appointment_id?: ?int, repeat?: bool} */
    public static function create(array $actor, array $in): array
    {
        $channel = Channel::forRole($actor['role_code']);
        if ($channel === null) {
            return ['ok' => false, 'errors' => ['Your role cannot record interactions.'], 'duplicates' => []];
        }
        $token = (string) ($in['form_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return ['ok' => false, 'errors' => ['This form has expired. Reload the page and try again.'], 'duplicates' => []];
        }
        if ($again = Interaction::findByToken($token)) {
            return self::repeat($actor, $again);
        }

        $mode = (string) ($in['customer_mode'] ?? '');
        if (!in_array($mode, ['new', 'existing'], true)) {
            return ['ok' => false, 'errors' => ['Choose whether this is a new or an existing customer.'], 'duplicates' => []];
        }

        $errors = [];
        $customer = null;
        $contact = $attr = null;
        if ($mode === 'existing') {
            $cid = self::id($in['customer_id'] ?? '');
            $customer = ($cid !== null && $cid !== false) ? CustomerService::selectable($cid) : null; // validated on the server, never trusted
            if (!$customer) {
                $errors[] = 'Find and select an existing customer. Archived or unknown customers cannot be used.';
            }
        } else {
            $cv = CustomerService::validateNew($actor, $in);
            $errors = $cv['errors'];
            $contact = $cv['contact'];
            $attr = $cv['attr'];
        }
        $v = self::validate($channel, $mode, $customer ? (int) $customer['id'] : null, $in, $attr['campaign_id'] ?? null);
        $errors = array_merge($errors, $v['errors']);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'duplicates' => []];
        }
        $d = $v['data'];

        $dups = ['all' => [], 'unacked' => []];
        if ($mode === 'new') {
            $dups = CustomerService::unacknowledgedDuplicates($contact, (string) ($in['ack_ids'] ?? ''));
            if ($dups['unacked'] !== []) {
                return ['ok' => false, 'errors' => [], 'duplicates' => $dups['all']];
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $customerId = $customer ? (int) $customer['id'] : 0;
            $code = $customer['customer_code'] ?? '';
            if ($mode === 'new') {
                $created = CustomerService::insertNew($actor, $contact, $attr, $channel, substr(hash('sha256', 'customer:' . $token), 0, 32), $d['interaction_at'], $dups['all']);
                $customerId = $created['id'];
                $code = $created['code'];
            }
            $iid = Interaction::insert([
                'customer_id' => $customerId, 'channel' => $channel, 'reason' => $d['reason'], 'is_first_contact' => $mode === 'new' ? 1 : 0, 'interaction_at' => $d['interaction_at'],
                'social_platform_id' => $d['social_platform_id'], 'interaction_source_id' => $d['interaction_source_id'], 'campaign_id' => $d['campaign_id'], 'service_id' => $d['service_id'],
                'booking_outcome' => $d['booking_outcome'], 'service_usage_outcome' => $d['service_usage_outcome'], 'nc_reason_id' => $d['nc_reason_id'], 'nc_other' => $d['nc_other'],
                'notes' => $d['notes'], 'user_id' => (int) $actor['id'], 'token' => $token,
            ]);
            $apptId = null;
            if ($d['appointment'] !== null) { // only a real booking creates an appointment, and exactly one
                $apptId = Appointment::create($customerId, $d['appointment']['service_id'], $d['appointment']['at'], $d['appointment']['status'], $iid, (int) $actor['id']);
                Appointment::link($iid, $apptId, 'booked');
                Audit::log((int) $actor['id'], 'appointment.create', 'appointment', $apptId, null, ['interaction_id' => $iid, 'customer_id' => $customerId, 'service_id' => $d['appointment']['service_id'], 'status' => $d['appointment']['status']]);
            }
            if ($d['followup_appointment_id'] !== null) {
                Appointment::link($iid, $d['followup_appointment_id'], ($channel === 'reception' && $d['service_usage_outcome'] === 'used') ? 'fulfilled' : 'followup');
            }
            Audit::log((int) $actor['id'], 'interaction.create', 'interaction', $iid, null, [
                'customer_id' => $customerId, 'channel' => $channel, 'reason' => $d['reason'], 'is_first_contact' => $mode === 'new', 'service_id' => $d['service_id'],
                'campaign_id' => $d['campaign_id'], 'platform_id' => $d['social_platform_id'], 'booking_outcome' => $d['booking_outcome'], 'service_usage_outcome' => $d['service_usage_outcome'],
                'appointment_id' => $apptId, 'linked_appointment_id' => $d['followup_appointment_id'], 'has_notes' => $d['notes'] !== null,
            ]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack(); // customer, interaction, appointment and audit rows all disappear together
            if ($t instanceof \PDOException && $t->getCode() === '23000' && ($again = Interaction::findByToken($token))) {
                return self::repeat($actor, $again); // an identical submission won the race
            }
            throw $t;
        }
        return ['ok' => true, 'errors' => [], 'duplicates' => [], 'id' => $iid, 'customer_code' => $code, 'appointment_id' => $apptId];
    }

    private static function repeat(array $actor, array $existing): array
    {
        return ['ok' => (int) $existing['recorded_by_user_id'] === (int) $actor['id'], 'errors' => [], 'duplicates' => [], 'id' => (int) $existing['id'], 'customer_code' => $existing['customer_code'], 'repeat' => true];
    }

    // ------------------------------------------------------------------ editing (24-hour rule)
    /** @return array{allowed: bool, admin: bool, message: ?string} */
    public static function canEdit(array $user, array $i): array
    {
        if (Auth::can('interactions.manage')) {
            return ['allowed' => true, 'admin' => true, 'message' => null]; // administrators may correct at any time (audited)
        }
        if (Channel::forRole($user['role_code']) !== $i['channel'] || (int) $i['recorded_by_user_id'] !== (int) $user['id']) {
            return ['allowed' => false, 'admin' => false, 'message' => 'You can only edit interactions you recorded yourself.'];
        }
        $hours = max(1, (int) Setting::get('entry_edit_window_hours', '24'));
        $st = Db::pdo()->prepare('SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) FROM interactions WHERE id = ?');
        $st->execute([$i['id']]);
        if ((int) $st->fetchColumn() > $hours * 60) {
            return ['allowed' => false, 'admin' => false, 'message' => "The $hours-hour edit window has passed. Ask an administrator to make the correction."];
        }
        return ['allowed' => true, 'admin' => false, 'message' => null];
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function update(array $actor, array $i, array $in, bool $adminOverride): array
    {
        $e = [];
        $channel = $i['channel'];
        $followup = $i['interaction_reason'] === 'appointment_followup';
        $notes = self::notes($in['notes'] ?? '', $e);

        $cid = self::id($in['interaction_campaign_id'] ?? '');
        if ($cid === false || ($cid !== null && !Reference::campaign($cid))) {
            $e[] = 'That campaign is not available.';
            $cid = $i['campaign_id'] === null ? null : (int) $i['campaign_id'];
        }
        $sid = self::id($in['service_id'] ?? '');
        if ($sid === false || ($sid !== null && !Reference::service($sid))) {
            $e[] = 'Choose a valid service.';
            $sid = $i['service_id'] === null ? null : (int) $i['service_id'];
        } elseif ($sid !== ($i['service_id'] === null ? null : (int) $i['service_id']) && $i['origin_appointment_id'] !== null) {
            $e[] = 'The service cannot be changed because an appointment was booked from this interaction.';
        }
        $pid = $i['social_platform_id'] === null ? null : (int) $i['social_platform_id'];
        if ($channel === 'social_dm') {
            $np = self::id($in['dm_platform_id'] ?? '');
            if ($np === null || $np === false || !Reference::platform($np)) {
                $e[] = 'Choose the social media platform used for this conversation.';
            } else {
                $pid = $np;
            }
        }
        $usage = $i['service_usage_outcome'];
        $ncId = $i['non_conversion_reason_id'] === null ? null : (int) $i['non_conversion_reason_id'];
        $ncOther = $i['non_conversion_other_text'];
        if ($channel === 'reception') {
            $u = (string) ($in['used'] ?? '');
            if (!in_array($u, $followup ? ['used', 'not_used', 'unknown'] : ['used', 'not_used'], true)) {
                $e[] = 'Say whether the customer used the service.';
            } else {
                $usage = $u;
                if ($u === 'used' && $sid === null) {
                    $e[] = 'Choose the service the customer used.';
                }
                $ncId = $ncOther = null;
                if ($u === 'not_used') {
                    $r = self::reasonOf($in, 'service_usage', !$followup, $e, 'the customer did not use the service');
                    $ncId = $r['id'];
                    $ncOther = $r['other'];
                }
            }
        } elseif ($i['booking_outcome'] === 'not_booked') {
            $r = self::reasonOf($in, 'booking', !$followup, $e, 'the customer did not book');
            $ncId = $r['id'];
            $ncOther = $r['other'];
        }
        if ($e !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($e))];
        }

        $new = ['notes' => $notes, 'campaign_id' => $cid, 'service_id' => $sid, 'social_platform_id' => $pid, 'service_usage_outcome' => $usage, 'nc_reason_id' => $ncId, 'nc_other' => $ncOther];
        $old = ['notes' => $i['notes'], 'campaign_id' => $i['campaign_id'] === null ? null : (int) $i['campaign_id'], 'service_id' => $i['service_id'] === null ? null : (int) $i['service_id'],
            'social_platform_id' => $i['social_platform_id'] === null ? null : (int) $i['social_platform_id'], 'service_usage_outcome' => $i['service_usage_outcome'],
            'nc_reason_id' => $i['non_conversion_reason_id'] === null ? null : (int) $i['non_conversion_reason_id'], 'nc_other' => $i['non_conversion_other_text']];
        $changed = array_keys(array_filter($new, static fn ($v, $k) => $v !== $old[$k], ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return ['ok' => true, 'errors' => []];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Interaction::updateEditable((int) $i['id'], $new);
            // Free-text (notes, explanations) is never copied into the audit log; coded values are.
            $coded = static fn (array $a) => array_diff_key(array_intersect_key($a, array_flip($changed)), ['notes' => 1, 'nc_other' => 1]);
            Audit::log((int) $actor['id'], 'interaction.update', 'interaction', (int) $i['id'], $coded($old), $coded($new) + ['changed_fields' => $changed, 'admin_override' => $adminOverride]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return ['ok' => true, 'errors' => []];
    }
}
