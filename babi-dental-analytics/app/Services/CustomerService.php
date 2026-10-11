<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Channel;
use App\Helpers\Db;
use App\Helpers\Phone;
use App\Models\Customer;
use App\Models\Setting;
use App\Validation\CustomerValidator;

final class CustomerService
{
    /**
     * Turns the single search box into a typed query. Rules keep result sets small and avoid bulk exposure:
     * "BDC-123" => customer ID; 4+ digits (phone punctuation allowed) => telephone; otherwise name tokens (2+ chars).
     * @return array{kind: string, error: ?string, tokens?: array, digits?: string, code?: string}
     */
    public static function parseQuery(string $raw): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', mb_substr($raw, 0, 80)) ?? '');
        if ($q === '') {
            return ['kind' => 'none', 'error' => null];
        }
        if (preg_match('/^bdc[-\s]?(\d{1,9})$/i', $q, $m)) {
            return ['kind' => 'code', 'error' => null, 'code' => sprintf('BDC-%06d', (int) $m[1])];
        }
        if (preg_match('/^[0-9 +().\-]+$/', $q)) {
            $d = Phone::normalize($q);
            return strlen($d) >= 4
                ? ['kind' => 'phone', 'error' => null, 'digits' => $d]
                : ['kind' => 'invalid', 'error' => 'Enter at least 4 digits of the telephone number.'];
        }
        $tokens = array_slice(array_values(array_filter(explode(' ', $q), static fn ($t) => $t !== '')), 0, 5);
        if (mb_strlen(implode('', $tokens)) < 2) {
            return ['kind' => 'invalid', 'error' => 'Enter at least 2 letters of the name, 4 digits of the phone, or an ID such as BDC-000123.'];
        }
        return ['kind' => 'name', 'error' => null, 'tokens' => $tokens];
    }

    public static function search(string $raw, bool $includeArchived, int $page): array
    {
        $q = self::parseQuery($raw);
        $per = max(5, min(50, (int) Setting::get('search_page_size', '10')));
        if (in_array($q['kind'], ['none', 'invalid'], true)) {
            return ['query' => $q, 'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per' => $per];
        }
        $first = Customer::search($q, $includeArchived, 1, 1);
        $pages = max(1, (int) ceil($first['total'] / $per));
        $page = max(1, min($page, $pages));
        $res = Customer::search($q, $includeArchived, $page, $per);
        return ['query' => $q, 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'pages' => $pages, 'per' => $per];
    }

    /** A customer may be attached to new interactions only while active. */
    public static function selectable(int $id): ?array
    {
        $c = Customer::find($id);
        return ($c && $c['status'] === 'active') ? $c : null;
    }

    /**
     * Shared by standalone registration (Phase 3) and "new customer + first interaction" (Phase 4).
     * @return array{errors: string[], contact: array, attr: array, channel: ?string}
     */
    public static function validateNew(array $actor, array $in): array
    {
        $contact = CustomerValidator::contact((string) ($in['name'] ?? ''), (string) ($in['phone'] ?? ''));
        $attr = CustomerValidator::attribution($in);
        $errors = array_merge($contact['errors'], $attr['errors']);
        $channel = Channel::forRole($actor['role_code']);
        if ($channel === null) { // administrators have no fixed channel: must state it
            $posted = (string) ($in['channel'] ?? '');
            if (!isset(Channel::LABELS[$posted])) {
                $errors[] = 'Choose the channel through which this customer first made contact.';
            } else {
                $channel = $posted;
            }
        }
        return ['errors' => $errors, 'contact' => $contact, 'attr' => $attr, 'channel' => $channel];
    }

    /** Duplicate candidates the staff member has not yet confirmed as "different people". */
    public static function unacknowledgedDuplicates(array $contact, string $ackIds): array
    {
        $dups = Customer::findDuplicates($contact['name'], $contact['phone_normalized']);
        $seen = array_map('intval', array_filter(explode(',', $ackIds), 'strlen'));
        return ['all' => $dups, 'unacked' => array_filter($dups, static fn ($d) => !in_array((int) $d['id'], $seen, true))];
    }

    /**
     * Inserts the customer + audit rows. Does NOT start a transaction: the caller owns it, so a customer can be
     * created atomically together with a first interaction.
     * @return array{id: int, code: string}
     */
    public static function insertNew(array $actor, array $contact, array $attr, string $channel, string $token, ?string $acquiredAt, array $dups = []): array
    {
        $code = Customer::nextCode();
        $id = Customer::insert([
            'code' => $code, 'token' => $token, 'name' => $contact['name'], 'phone' => $contact['phone'], 'phone_normalized' => $contact['phone_normalized'],
            'source_id' => $attr['source_id'], 'platform_id' => $attr['platform_id'], 'source_other' => $attr['source_other'], 'campaign_id' => $attr['campaign_id'],
            'channel' => $channel, 'acquired_at' => $acquiredAt, 'user_id' => (int) $actor['id'],
        ]);
        Audit::log((int) $actor['id'], 'customer.create', 'customer', $id, null, [
            'customer_code' => $code, 'source_id' => $attr['source_id'], 'platform_id' => $attr['platform_id'], 'campaign_id' => $attr['campaign_id'],
            'channel' => $channel, 'has_name' => $contact['name'] !== null, 'has_phone' => $contact['phone'] !== null,
        ]);
        if ($dups !== []) {
            Audit::log((int) $actor['id'], 'customer.duplicate_override', 'customer', $id, null, ['matched_customer_ids' => array_map(static fn ($d) => (int) $d['id'], $dups)]);
        }
        return ['id' => $id, 'code' => $code];
    }

    /**
     * Registers a customer. Duplicate candidates produce a warning (not an error) the first time;
     * the staff member then confirms they are different people by re-submitting with the ids they saw.
     * @return array{ok: bool, errors: string[], duplicates: array, id?: int, repeat?: bool}
     */
    public static function register(array $actor, array $in): array
    {
        $v = self::validateNew($actor, $in);
        $errors = $v['errors'];
        $token = (string) ($in['form_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $errors[] = 'This form has expired. Reload the page and try again.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'duplicates' => []];
        }

        // Same submission already stored (double click / retry): return it instead of creating another.
        $existing = Customer::findByToken($token);
        if ($existing) {
            return ['ok' => (int) $existing['created_by_user_id'] === (int) $actor['id'], 'errors' => [], 'duplicates' => [], 'id' => (int) $existing['id'], 'repeat' => true];
        }

        $d = self::unacknowledgedDuplicates($v['contact'], (string) ($in['ack_ids'] ?? ''));
        if ($d['unacked'] !== []) {
            return ['ok' => false, 'errors' => [], 'duplicates' => $d['all']];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $created = self::insertNew($actor, $v['contact'], $v['attr'], $v['channel'], $token, null, $d['all']);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000' && ($again = Customer::findByToken($token))) { // lost a race with an identical submission
                return ['ok' => (int) $again['created_by_user_id'] === (int) $actor['id'], 'errors' => [], 'duplicates' => [], 'id' => (int) $again['id'], 'repeat' => true];
            }
            throw $e;
        }
        return ['ok' => true, 'errors' => [], 'duplicates' => [], 'id' => $created['id']];
    }

    /** Contact correction (name/phone only). Never touches acquisition fields. */
    public static function updateContact(array $actor, int $id, array $in): array
    {
        $c = Customer::find($id);
        if (!$c) {
            return ['ok' => false, 'errors' => ['Customer not found.']];
        }
        $contact = CustomerValidator::contact((string) ($in['name'] ?? ''), (string) ($in['phone'] ?? ''));
        if ($contact['errors'] !== []) {
            return ['ok' => false, 'errors' => $contact['errors']];
        }
        $changed = [];
        if ($contact['name'] !== $c['name']) { $changed[] = 'name'; }
        if ($contact['phone'] !== $c['phone']) { $changed[] = 'phone'; }
        if ($changed === []) {
            return ['ok' => true, 'errors' => []];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Customer::updateContact($id, $contact['name'], $contact['phone'], $contact['phone_normalized']);
            Audit::log((int) $actor['id'], 'customer.update', 'customer', $id, null, ['changed_fields' => $changed]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return ['ok' => true, 'errors' => []];
    }

    public static function toggleArchive(array $actor, int $id): ?string
    {
        $c = Customer::find($id);
        if (!$c || $c['status'] === 'merged') {
            return null;
        }
        $new = $c['status'] === 'active' ? 'archived' : 'active';
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Customer::setStatus($id, $new);
            Audit::log((int) $actor['id'], $new === 'archived' ? 'customer.archive' : 'customer.unarchive', 'customer', $id, ['status' => $c['status']], ['status' => $new]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return $new;
    }

    /**
     * The ONLY path that changes original acquisition. Requires a written reason, records a correction row
     * and an audit event, and lifts the database write-once trigger for this single transaction.
     */
    public static function correctSource(array $actor, int $id, array $in): array
    {
        $c = Customer::find($id);
        if (!$c) {
            return ['ok' => false, 'errors' => ['Customer not found.']];
        }
        $attr = CustomerValidator::attribution($in);
        $errors = $attr['errors'];
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            $errors[] = 'Give a reason for the correction (10 to 300 characters).';
        }
        $n = static fn ($v) => $v === null ? null : (int) $v;
        if ($errors === [] && (int) $c['original_source_id'] === $attr['source_id'] && $n($c['original_social_platform_id']) === $attr['platform_id']
            && $n($c['original_campaign_id']) === $attr['campaign_id'] && $c['original_source_other'] === $attr['source_other']) {
            $errors[] = 'Nothing changed.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO customer_source_corrections (customer_id, old_source_id, new_source_id, old_social_platform_id, new_social_platform_id, old_campaign_id, new_campaign_id, reason, corrected_by_user_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $c['original_source_id'], $attr['source_id'], $c['original_social_platform_id'], $attr['platform_id'], $c['original_campaign_id'], $attr['campaign_id'], $reason, (int) $actor['id']]);
            $pdo->exec('SET @babi_allow_source_correction = 1');
            $pdo->prepare('UPDATE customers SET original_source_id = ?, original_social_platform_id = ?, original_source_other = ?, original_campaign_id = ? WHERE id = ?')
                ->execute([$attr['source_id'], $attr['platform_id'], $attr['source_other'], $attr['campaign_id'], $id]);
            $pdo->exec('SET @babi_allow_source_correction = 0');
            Audit::log((int) $actor['id'], 'customer.source_correct', 'customer', $id,
                ['source_id' => (int) $c['original_source_id'], 'platform_id' => $c['original_social_platform_id'], 'campaign_id' => $c['original_campaign_id']],
                ['source_id' => $attr['source_id'], 'platform_id' => $attr['platform_id'], 'campaign_id' => $attr['campaign_id'], 'reason' => $reason]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            $pdo->exec('SET @babi_allow_source_correction = 0');
            throw $t;
        }
        return ['ok' => true, 'errors' => []];
    }
}
