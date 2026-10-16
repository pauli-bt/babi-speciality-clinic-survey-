<?php
declare(strict_types=1);
// Phase 4 suite: the three channel questionnaires, new/existing customers, appointments, atomicity, 24-hour rule.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $P4 = 'Orchard-Violet-5731';
$role = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$src = array_column($db->query('SELECT name,id FROM acquisition_sources')->fetchAll(), 'id', 'name');
$plat = array_column($db->query('SELECT name,id FROM social_platforms')->fetchAll(), 'id', 'name');
$svc = array_column($db->query('SELECT name,id FROM services')->fetchAll(), 'id', 'name');
$rsn = array_column($db->query('SELECT name,id FROM non_conversion_reasons')->fetchAll(), 'id', 'name');
$adminId = (int) $db->query("SELECT id FROM users WHERE username='boss'")->fetchColumn();
$hash = password_hash($P4, PASSWORD_BCRYPT, ['cost' => 12]);
foreach ([['p4_rec', 'receptionist'], ['p4_rec2', 'receptionist'], ['p4_call', 'call_operator'], ['p4_soc', 'social_tracker'], ['p4_mkt', 'marketing']] as [$u, $r]) {
    $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password, password_changed_at) VALUES (?,?,?,?,0,NOW())')->execute([ucfirst($r) . ' Four', $u, $hash, $role[$r]]);
}
$uid = fn(string $u) => (int) $db->query("SELECT id FROM users WHERE username='$u'")->fetchColumn();
$login = function (string $u, string $p) { $c = new Client(); $c->login($u, $p); return $c; };
$REC = $login('p4_rec', $P4); $REC2 = $login('p4_rec2', $P4); $CALL = $login('p4_call', $P4); $SOC = $login('p4_soc', $P4); $MKT = $login('p4_mkt', $P4); $ADM = $login('boss', $ADMIN_PW);
$db->exec("INSERT INTO campaigns (name, platform_id, status) VALUES ('Eid Promo', {$plat['Instagram']}, 'active'), ('Draft Promo', {$plat['Instagram']}, 'draft')");
$CAMP = (int) $db->query("SELECT id FROM campaigns WHERE name='Eid Promo'")->fetchColumn(); $DRAFT = (int) $db->query("SELECT id FROM campaigns WHERE name='Draft Promo'")->fetchColumn();

$mkCust = function (string $code, string $name, ?string $phone, string $status = 'active') use ($db, $adminId, $src) {
    $norm = $phone === null ? null : preg_replace('/\D/', '', $phone);
    $db->prepare("INSERT INTO customers (customer_code,name,phone,phone_normalized,original_source_id,first_acquisition_channel,first_acquisition_at,created_by_user_id,status) VALUES (?,?,?,?,?,'phone',NOW() - INTERVAL 10 DAY,?,?)")
        ->execute([$code, $name, $phone, $norm, $src['Friend or family referral'], $adminId, $status]);
    return (int) $db->lastInsertId();
};
$C1 = $mkCust('BDC-410001', 'Zelalem Tadesse', '0912345678');
$C2 = $mkCust('BDC-410002', 'Other Customer', '0955000111');
$ARCH = $mkCust('BDC-410003', 'Archived Four', '0977000222', 'archived');

function tokens(string $html): array { preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $a); preg_match('/name="form_token" value="([a-f0-9]+)"/', $html, $b); return [$a[1] ?? '', $b[1] ?? '']; }
function ix(Client $c, string $base, array $f, ?string $token = null, bool $csrf = true): array {
    $r = $c->req('GET', $base . '/new'); [$cs, $ft] = tokens($r['body']);
    $data = $f + ['form_token' => $token ?? $ft, 'ack_ids' => ''];
    if ($csrf) { $data['_csrf'] = $cs; }
    return $c->req('POST', $base, $data);
}
function iid(array $r): int { return preg_match('#/interactions/(\d+)$#', $r['loc'], $m) ? (int) $m[1] : 0; }
$at = fn(int $minAgo = 5) => date('Y-m-d\TH:i', time() - 60 * $minAgo);
$later = fn(int $days = 3) => date('Y-m-d\TH:i', time() + 86400 * $days);
$cnt = fn(string $t) => (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
$irow = fn(int $id) => $db->query("SELECT * FROM interactions WHERE id=$id")->fetch();
$crow = fn(int $id) => $db->query("SELECT * FROM customers WHERE id=$id")->fetch();
$audit = fn(string $a) => (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action=" . $db->quote($a))->fetchColumn();
$REC_B = '/reception/interactions'; $CALL_B = '/call-operator/interactions'; $SOC_B = '/social-media/interactions';
$recEx = fn(array $o = []) => $o + ['customer_mode' => 'existing', 'customer_id' => $C1, 'reason' => 'another_service', 'interaction_at' => $at(), 'service_id' => $svc['Teeth cleaning'], 'used' => 'used'];
$callEx = fn(array $o = []) => $o + ['customer_mode' => 'existing', 'customer_id' => $C1, 'reason' => 'another_service', 'interaction_at' => $at(), 'service_id' => $svc['Filling'], 'booking' => 'confirmed', 'appointment_at' => $later()];
$socEx = fn(array $o = []) => $o + ['customer_mode' => 'existing', 'customer_id' => $C1, 'reason' => 'another_service', 'interaction_at' => $at(), 'service_id' => $svc['Teeth whitening'], 'dm_platform_id' => $plat['Instagram'], 'booking' => 'confirmed', 'appointment_at' => $later()];
$newCust = fn(array $o = []) => $o + ['customer_mode' => 'new', 'name' => 'Newcomer One', 'phone' => '', 'source_id' => $src['Google Search'], 'interaction_at' => $at()];

// ------------------------------------------------------------------ A. forms
section('A. The three forms render with channel-specific fields');
$fr = $REC->req('GET', "$REC_B/new")['body']; $fc = $CALL->req('GET', "$CALL_B/new")['body']; $fs = $SOC->req('GET', "$SOC_B/new")['body'];
t('reception form: usage question, no booking/DM fields', str_contains($fr, 'name="used"') && !str_contains($fr, 'name="booking"') && !str_contains($fr, 'dm_platform_id') && !str_contains($fr, 'name="appointment_at"'));
t('telephone form: booking + appointment fields, no usage/DM fields', str_contains($fc, 'name="booking"') && str_contains($fc, 'name="appointment_at"') && !str_contains($fc, 'name="used"') && !str_contains($fc, 'dm_platform_id'));
t('social DM form: platform + booking fields, no usage field', str_contains($fs, 'name="dm_platform_id"') && str_contains($fs, 'name="booking"') && !str_contains($fs, 'name="used"'));
foreach (['reception' => $fr, 'telephone' => $fc, 'social' => $fs] as $n => $h) {
    t("$n form: logo, viewport, token, customer picker, conditional-field hooks, no channel field", str_contains($h, '/assets/images/babi-logo.png') && str_contains($h, 'name="viewport"') && preg_match('/name="form_token" value="[a-f0-9]{32}"/', $h) && str_contains($h, 'data-picker') && str_contains($h, 'data-show-when') && !str_contains($h, 'name="channel"'));
    t("$n form: no inline script/style/handlers (CSP-safe)", !preg_match('/<script(?![^>]*\bsrc=)|\sstyle=|\son[a-z]+=/i', $h));
}
t('interaction-form.js is served', $REC->req('GET', '/assets/js/interaction-form.js')['status'] === 200);

// ------------------------------------------------------------------ B. reception
section('B. Reception questionnaire');
$a0 = $cnt('appointments'); $i0 = $cnt('interactions');
$r = ix($REC, $REC_B, $recEx()); $R1 = iid($r); $row = $irow($R1);
t('existing customer + service used -> interaction created', $r['status'] === 302 && $R1 > 0 && $cnt('interactions') === $i0 + 1);
t('stored as a reception interaction linked to the customer', $row['channel'] === 'reception' && (int) $row['customer_id'] === $C1 && $row['interaction_reason'] === 'another_service' && (int) $row['is_first_contact'] === 0 && (int) $row['recorded_by_user_id'] === $uid('p4_rec'));
t('outcome: usage = used, booking not applicable, service stored', $row['service_usage_outcome'] === 'used' && $row['booking_outcome'] === 'not_applicable' && (int) $row['service_id'] === $svc['Teeth cleaning']);
t('reception never creates an appointment', $cnt('appointments') === $a0);
t('success flash on the detail page', str_contains($REC->req('GET', "/interactions/$R1")['body'], 'Interaction recorded for BDC-410001'));
$r = ix($REC, $REC_B, $recEx(['used' => 'not_used', 'non_conversion_reason_id' => $rsn['Pricing']])); $row = $irow(iid($r));
t('visit without service use (not_used) with a reason', $r['status'] === 302 && $row['service_usage_outcome'] === 'not_used' && (int) $row['non_conversion_reason_id'] === $rsn['Pricing']);
$n = $cnt('interactions');
t('not_used without a reason is rejected', ix($REC, $REC_B, $recEx(['used' => 'not_used']))['status'] === 422);
t('a booking-only reason ("Undecided") is rejected for reception', ix($REC, $REC_B, $recEx(['used' => 'not_used', 'non_conversion_reason_id' => $rsn['Undecided']]))['status'] === 422);
t('used without a service is rejected', ix($REC, $REC_B, $recEx(['service_id' => '']))['status'] === 422);
t('missing usage answer is rejected', ix($REC, $REC_B, $recEx(['used' => '']))['status'] === 422);
t('"unknown" usage is rejected outside follow-ups', ix($REC, $REC_B, $recEx(['used' => 'unknown']))['status'] === 422);
$r = ix($REC, $REC_B, $recEx(['reason' => 'appointment_followup', 'used' => 'unknown', 'service_id' => ''])); $row = $irow(iid($r));
t('"unknown" usage is allowed for a follow-up', $r['status'] === 302 && $row['service_usage_outcome'] === 'unknown');
$r = ix($REC, $REC_B, $recEx(['used' => 'not_used', 'non_conversion_reason_id' => $rsn['Other'], 'non_conversion_other_text' => 'Came for a friend'])); $row = $irow(iid($r));
t('"Other" reason keeps its explanation', $row['non_conversion_other_text'] === 'Came for a friend');
$n = $cnt('interactions');
foreach (['booking' => 'confirmed', 'appointment_at' => $later(), 'dm_platform_id' => $plat['Instagram']] as $f => $v) {
    $r = ix($REC, $REC_B, $recEx([$f => $v]));
    t("reception cannot submit the telephone/DM field \"$f\"", $r['status'] === 422 && str_contains($r['body'], 'does not belong to Reception'));
}
t('nothing saved by the cross-channel submissions', $cnt('interactions') === $n);
t('future interaction time rejected', ix($REC, $REC_B, $recEx(['interaction_at' => $later(2)]))['status'] === 422);
t('interaction older than 30 days rejected', ix($REC, $REC_B, $recEx(['interaction_at' => $at(60 * 24 * 31)]))['status'] === 422);
t('invalid / empty date rejected', ix($REC, $REC_B, $recEx(['interaction_at' => 'garbage']))['status'] === 422 && ix($REC, $REC_B, $recEx(['interaction_at' => '']))['status'] === 422);
t('a back-dated interaction within the limit is accepted', ix($REC, $REC_B, $recEx(['interaction_at' => $at(60 * 24 * 29)]))['status'] === 302);
t('notes over 1000 characters rejected', ix($REC, $REC_B, $recEx(['notes' => str_repeat('n', 1001)]))['status'] === 422);
$r = ix($REC, $REC_B, $recEx(['used' => '', 'notes' => 'Keep this note']));
t('entered values are preserved after a validation error', $r['status'] === 422 && str_contains($r['body'], 'Keep this note') && str_contains($r['body'], 'value="' . $C1 . '"'));
$r = ix($REC, $REC_B, $recEx(['notes' => '<script>alert(1)</script> secret-note-text'])); $XSS = iid($r);
$b = $REC->req('GET', "/interactions/$XSS")['body'];
t('notes are escaped on display', str_contains($b, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($b, '<script>alert(1)'));

// ------------------------------------------------------------------ C. telephone
section('C. Telephone questionnaire');
$a0 = $cnt('appointments'); $e0 = $cnt('appointment_events');
$r = ix($CALL, $CALL_B, $callEx()); $P1 = iid($r); $row = $irow($P1);
t('confirmed booking creates the interaction', $r['status'] === 302 && $row['channel'] === 'phone' && $row['booking_outcome'] === 'confirmed' && $row['service_usage_outcome'] === 'not_applicable');
$ap = $db->query("SELECT * FROM appointments WHERE origin_interaction_id=$P1")->fetch();
t('exactly one appointment was created, for this customer, service and interaction', $cnt('appointments') === $a0 + 1 && (int) $ap['customer_id'] === $C1 && (int) $ap['service_id'] === $svc['Filling'] && (int) $ap['origin_interaction_id'] === $P1 && (int) $ap['created_by_user_id'] === $uid('p4_call'));
t('initial status "confirmed", never "completed"', $ap['status'] === 'confirmed' && $ap['appointment_at'] !== null);
$ev = $db->query("SELECT * FROM appointment_events WHERE appointment_id={$ap['id']}")->fetchAll();
t('status history recorded (null -> confirmed, linked to the interaction)', count($ev) === 1 && $ev[0]['from_status'] === null && $ev[0]['to_status'] === 'confirmed' && (int) $ev[0]['interaction_id'] === $P1);
t('interaction <-> appointment link stored as "booked"', (int) $db->query("SELECT COUNT(*) FROM interaction_appointments WHERE interaction_id=$P1 AND appointment_id={$ap['id']} AND link_type='booked'")->fetchColumn() === 1);
$a1 = $cnt('appointments');
$r = ix($CALL, $CALL_B, $callEx(['booking' => 'requested', 'appointment_at' => ''])); $row = $irow(iid($r));
$ap2 = $db->query("SELECT * FROM appointments WHERE origin_interaction_id={$row['id']}")->fetch();
t('requested booking without a time creates a "requested" appointment', $r['status'] === 302 && $row['booking_outcome'] === 'requested' && $ap2['status'] === 'requested' && $ap2['appointment_at'] === null);
$a2 = $cnt('appointments'); $n = $cnt('interactions');
t('confirmed booking without a time is rejected', ix($CALL, $CALL_B, $callEx(['appointment_at' => '']))['status'] === 422);
t('appointment before the interaction time is rejected', ix($CALL, $CALL_B, $callEx(['appointment_at' => $at(600)]))['status'] === 422);
t('appointment more than a year ahead is rejected', ix($CALL, $CALL_B, $callEx(['appointment_at' => $later(400)]))['status'] === 422);
t('booking without a service is rejected', ix($CALL, $CALL_B, $callEx(['service_id' => '']))['status'] === 422);
t('invalid appointment time rejected', ix($CALL, $CALL_B, $callEx(['appointment_at' => 'tomorrow-ish']))['status'] === 422);
t('missing booking answer rejected', ix($CALL, $CALL_B, $callEx(['booking' => '']))['status'] === 422);
t('failed bookings create neither interaction nor appointment', $cnt('appointments') === $a2 && $cnt('interactions') === $n);
$r = ix($CALL, $CALL_B, $callEx(['booking' => 'not_booked', 'appointment_at' => '', 'non_conversion_reason_id' => $rsn['Undecided']])); $row = $irow(iid($r));
t('inquiry without booking stored, no appointment, reason kept', $r['status'] === 302 && $row['booking_outcome'] === 'not_booked' && (int) $row['non_conversion_reason_id'] === $rsn['Undecided'] && $cnt('appointments') === $a2);
t('not_booked without a reason rejected', ix($CALL, $CALL_B, $callEx(['booking' => 'not_booked', 'appointment_at' => '']))['status'] === 422);
$r = ix($CALL, $CALL_B, $callEx(['booking' => 'not_booked', 'appointment_at' => '', 'non_conversion_reason_id' => $rsn['Pricing']]));
t('stray appointment time on a "not booked" call is ignored', $r['status'] === 302 && $cnt('appointments') === $a2);
$r = ix($CALL, $CALL_B, $callEx(['used' => 'used']));
t('telephone cannot submit the reception field "used"', $r['status'] === 422 && str_contains($r['body'], 'does not belong to Telephone'));
t('telephone cannot submit a DM platform', ix($CALL, $CALL_B, $callEx(['dm_platform_id' => $plat['Instagram']]))['status'] === 422);
// follow-up branch
$a3 = $cnt('appointments');
$r = ix($CALL, $CALL_B, $callEx(['reason' => 'appointment_followup', 'booking' => 'not_applicable', 'appointment_at' => '', 'followup_appointment_id' => (int) $ap['id']])); $row = $irow(iid($r));
t('follow-up call linked to an existing appointment', $r['status'] === 302 && $row['booking_outcome'] === 'not_applicable' && (int) $db->query("SELECT COUNT(*) FROM interaction_appointments WHERE interaction_id={$row['id']} AND appointment_id={$ap['id']} AND link_type='followup'")->fetchColumn() === 1);
t('a follow-up does NOT create a new appointment and is not a new customer', $cnt('appointments') === $a3 && (int) $row['is_first_contact'] === 0);
t('"not applicable" booking is rejected for non-follow-ups', ix($CALL, $CALL_B, $callEx(['booking' => 'not_applicable', 'appointment_at' => '']))['status'] === 422);
$apOther = $db->query("SELECT id FROM appointments WHERE customer_id<>$C1 LIMIT 1")->fetchColumn();
$db->exec("INSERT INTO appointments (customer_id, service_id, status, created_by_user_id) VALUES ($C2, {$svc['Filling']}, 'requested', $adminId)"); $apC2 = (int) $db->lastInsertId();
t("another customer's appointment cannot be linked", ix($CALL, $CALL_B, $callEx(['reason' => 'appointment_followup', 'booking' => 'not_applicable', 'appointment_at' => '', 'followup_appointment_id' => $apC2]))['status'] === 422);
t('an appointment can only be linked on a follow-up reason', ix($CALL, $CALL_B, $callEx(['followup_appointment_id' => (int) $ap['id']]))['status'] === 422);
// campaign response on the phone
$n = $cnt('interactions');
t('campaign response needs an advertisement source', ix($CALL, $CALL_B, $callEx(['reason' => 'campaign_response']))['status'] === 422);
$r = ix($CALL, $CALL_B, $callEx(['reason' => 'campaign_response', 'ad_source_id' => $src['Online advertisement']])); $row = $irow(iid($r));
t('campaign response via a non-social source stored as interaction source', $r['status'] === 302 && (int) $row['interaction_source_id'] === $src['Online advertisement'] && $row['social_platform_id'] === null && $row['campaign_id'] === null);
$r = ix($CALL, $CALL_B, $callEx(['reason' => 'campaign_response', 'ad_source_id' => $src['Social media'], 'ad_platform_id' => $plat['Facebook']])); $row = $irow(iid($r));
t('campaign response via social media stores the platform', $r['status'] === 302 && (int) $row['social_platform_id'] === $plat['Facebook'] && $row['interaction_source_id'] === null);
t('social advertisement without a platform rejected', ix($CALL, $CALL_B, $callEx(['reason' => 'campaign_response', 'ad_source_id' => $src['Social media']]))['status'] === 422);
t('"Unknown" is not a valid advertisement source', ix($CALL, $CALL_B, $callEx(['reason' => 'campaign_response', 'ad_source_id' => $src['Unknown / does not remember']]))['status'] === 422);

// ------------------------------------------------------------------ D. social DM
section('D. Social media DM questionnaire');
$a0 = $cnt('appointments');
t('DM without a platform is rejected', ix($SOC, $SOC_B, $socEx(['dm_platform_id' => '']))['status'] === 422);
$r = ix($SOC, $SOC_B, $socEx()); $S1 = iid($r); $row = $irow($S1);
t('DM booking stored with the conversation platform', $r['status'] === 302 && $row['channel'] === 'social_dm' && (int) $row['social_platform_id'] === $plat['Instagram'] && $row['booking_outcome'] === 'confirmed');
$ap = $db->query("SELECT * FROM appointments WHERE origin_interaction_id=$S1")->fetch();
t('DM booking created exactly one linked appointment', $cnt('appointments') === $a0 + 1 && (int) $ap['customer_id'] === $C1 && $ap['status'] === 'confirmed');
t('no campaign is auto-assigned even though an active Instagram campaign exists', $row['campaign_id'] === null);
$r = ix($SOC, $SOC_B, $socEx(['interaction_campaign_id' => $CAMP, 'booking' => 'not_booked', 'appointment_at' => '', 'non_conversion_reason_id' => $rsn['Pricing']])); $row = $irow(iid($r));
t('a campaign chosen by staff is stored', $r['status'] === 302 && (int) $row['campaign_id'] === $CAMP);
t('draft campaign rejected', ix($SOC, $SOC_B, $socEx(['interaction_campaign_id' => $DRAFT]))['status'] === 422);
t('non-existent campaign rejected', ix($SOC, $SOC_B, $socEx(['interaction_campaign_id' => 99999]))['status'] === 422);
t('social DM cannot submit the reception field "used"', ix($SOC, $SOC_B, $socEx(['used' => 'used']))['status'] === 422);
$r = ix($SOC, $SOC_B, $socEx(['reason' => 'campaign_response', 'booking' => 'requested', 'appointment_at' => ''])); $row = $irow(iid($r));
t('DM campaign response needs no extra source (the DM platform is the platform)', $r['status'] === 302 && $row['interaction_reason'] === 'campaign_response' && (int) $row['social_platform_id'] === $plat['Instagram']);
$c0 = $cnt('customers');
$r = ix($SOC, $SOC_B, $newCust(['name' => 'Newcomer DM', 'dm_platform_id' => $plat['Instagram'], 'booking' => 'not_booked', 'non_conversion_reason_id' => $rsn['Pricing']])); $row = $irow(iid($r)); $nc = $crow((int) $row['customer_id']);
t('new DM customer: original source (Google Search) and DM platform (Instagram) are kept separately', $r['status'] === 302 && (int) $nc['original_source_id'] === $src['Google Search'] && $nc['original_social_platform_id'] === null && (int) $row['social_platform_id'] === $plat['Instagram'] && $nc['first_acquisition_channel'] === 'social_dm');

// ------------------------------------------------------------------ E. new customer + first interaction
section('E. New customer + first interaction in one operation');
$c0 = $cnt('customers'); $i0 = $cnt('interactions'); $a0 = $cnt('appointments'); $counter0 = (int) $db->query('SELECT next_value FROM customer_code_counter')->fetchColumn();
$when = $at(60 * 48);
$r = ix($CALL, $CALL_B, $newCust(['name' => 'Newcomer Phone', 'phone' => '+251 933 000 444', 'source_id' => $src['Social media'], 'platform_id' => $plat['TikTok'], 'campaign_id' => $CAMP, 'interaction_at' => $when, 'service_id' => $svc['Root canal'], 'booking' => 'confirmed', 'appointment_at' => $later(5)]));
$E1 = iid($r); $ei = $irow($E1); $ec = $crow((int) $ei['customer_id']);
t('one operation creates 1 customer + 1 interaction + 1 appointment', $r['status'] === 302 && $cnt('customers') === $c0 + 1 && $cnt('interactions') === $i0 + 1 && $cnt('appointments') === $a0 + 1);
t('customer code follows the Phase 3 format and sequence', (bool) preg_match('/^BDC-\d{6}$/', $ec['customer_code']) && (int) $db->query('SELECT next_value FROM customer_code_counter')->fetchColumn() === $counter0 + 1);
t('interaction is the first contact (reason first_contact, flag set)', (int) $ei['is_first_contact'] === 1 && $ei['interaction_reason'] === 'first_contact' && $ei['channel'] === 'phone');
t('original acquisition stored: source, platform, campaign, first channel', (int) $ec['original_source_id'] === $src['Social media'] && (int) $ec['original_social_platform_id'] === $plat['TikTok'] && (int) $ec['original_campaign_id'] === $CAMP && $ec['first_acquisition_channel'] === 'phone');
t('first acquisition time equals the (back-dated) interaction time', $ec['first_acquisition_at'] === $ei['interaction_at'] && str_starts_with($ei['interaction_at'], substr($when, 0, 10)));
t('phone normalised, appointment linked to the new customer and interaction', $ec['phone_normalized'] === '0933000444' && (int) $db->query("SELECT COUNT(*) FROM appointments WHERE customer_id={$ec['id']} AND origin_interaction_id=$E1")->fetchColumn() === 1);
t('the interaction campaign is the original campaign (not invented)', (int) $ei['campaign_id'] === $CAMP);
$r = ix($REC, $REC_B, $newCust(['name' => 'Newcomer Reception', 'used' => 'used', 'service_id' => $svc['General check-up']])); $row = $irow(iid($r));
t('new customer at reception with service used', $r['status'] === 302 && $row['channel'] === 'reception' && $row['service_usage_outcome'] === 'used' && (int) $row['is_first_contact'] === 1);
$c1n = $cnt('customers'); $i1n = $cnt('interactions'); $counter1 = (int) $db->query('SELECT next_value FROM customer_code_counter')->fetchColumn();
$r = ix($CALL, $CALL_B, $newCust(['name' => '', 'phone' => '', 'booking' => 'not_booked', 'non_conversion_reason_id' => $rsn['Pricing']]));
t('invalid customer part -> nothing saved', $r['status'] === 422 && str_contains($r['body'], 'name or telephone') && $cnt('customers') === $c1n && $cnt('interactions') === $i1n);
$r = ix($CALL, $CALL_B, $newCust(['name' => 'Newcomer Rollback', 'booking' => 'confirmed', 'appointment_at' => '', 'service_id' => $svc['Filling'], 'notes' => 'Kept on error']));
t('invalid interaction part -> no customer is left behind', $r['status'] === 422 && $cnt('customers') === $c1n && $cnt('interactions') === $i1n && (int) $db->query('SELECT next_value FROM customer_code_counter')->fetchColumn() === $counter1);
t('values (customer + interaction) preserved after the error', str_contains($r['body'], 'value="Newcomer Rollback"') && str_contains($r['body'], 'Kept on error'));
t('missing new/existing choice rejected', ix($CALL, $CALL_B, $callEx(['customer_mode' => '']))['status'] === 422);
t('social source without platform rejected in the new-customer part', ix($CALL, $CALL_B, $newCust(['name' => 'X Platformless', 'source_id' => $src['Social media'], 'booking' => 'not_booked', 'non_conversion_reason_id' => $rsn['Pricing']]))['status'] === 422);
t('new mode ignores a posted customer_id (a new customer is created)', (function () use ($CALL, $CALL_B, $newCust, $C1, $rsn, $cnt, $c1n) { $r = ix($CALL, $CALL_B, $newCust(['name' => 'Newcomer Ignore', 'customer_id' => $C1, 'booking' => 'not_booked', 'non_conversion_reason_id' => $rsn['Pricing']])); return $r['status'] === 302 && $cnt('customers') === $c1n + 1; })());
// duplicates
$c2n = $cnt('customers'); $i2n = $cnt('interactions');
$dupF = $newCust(['name' => 'Different Person', 'phone' => '+251 912 345 678', 'booking' => 'not_booked', 'non_conversion_reason_id' => $rsn['Pricing']]);
$r = ix($CALL, $CALL_B, $dupF);
t('shared phone -> duplicate warning, nothing saved', $r['status'] === 200 && str_contains($r['body'], 'Possible existing customer') && str_contains($r['body'], 'BDC-410001') && str_contains($r['body'], 'Same phone number') && $cnt('customers') === $c2n && $cnt('interactions') === $i2n);
t('warning masks the phone and offers "use this customer" returning to this form', str_contains($r['body'], '••••••5678') && !str_contains($r['body'], '0912345678') && str_contains($r['body'], 'name="back" value="' . $CALL_B . '/new"'));
$r = $CALL->req('POST', $CALL_B, $dupF + ['_csrf' => tokens($CALL->req('GET', "$CALL_B/new")['body'])[0], 'form_token' => bin2hex(random_bytes(16)), 'ack_ids' => (string) $C1]);
$row = $irow(iid($r));
t('confirming "different people" saves the new customer + interaction; sharing a phone is allowed', $r['status'] === 302 && $cnt('customers') === $c2n + 1 && (int) $row['customer_id'] !== $C1 && (int) $db->query("SELECT COUNT(*) FROM customers WHERE phone_normalized='0912345678'")->fetchColumn() === 2);
t('duplicate override audited', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='customer.duplicate_override' AND entity_id={$row['customer_id']}")->fetchColumn() === 1);
$r = $CALL->req('POST', '/customers/' . $C1 . '/select', ['_csrf' => tokens($CALL->req('GET', "$CALL_B/new")['body'])[0], 'back' => "$CALL_B/new"]);
$form = $CALL->req('GET', "$CALL_B/new")['body'];
t('"use this customer" returns to the form with that customer pre-selected', $r['status'] === 302 && $r['loc'] === "$CALL_B/new" && str_contains($form, 'name="customer_id" value="' . $C1 . '"') && preg_match('/id="mode_existing" value="existing" checked/', $form) === 1);
t('the select-and-return redirect only accepts the three form paths', $CALL->req('POST', '/customers/' . $C1 . '/select', ['_csrf' => tokens($form)[0], 'back' => 'https://evil.example/'])['loc'] === '/customers/' . $C1);
$CALL->post('/call-operator', '/customers/selection/clear', []);

// ------------------------------------------------------------------ F. existing customers
section('F. Existing customers, reasons and attribution');
$snap = $crow($C1); $c0 = $cnt('customers');
foreach (['appointment_followup', 'another_service', 'campaign_response'] as $reason) {
    $o = $recEx(['reason' => $reason]); if ($reason === 'campaign_response') { $o['ad_source_id'] = $src['Online advertisement']; }
    $r = ix($REC, $REC_B, $o); $row = $irow(iid($r));
    t("returning reason \"$reason\" is stored as a structured value", $r['status'] === 302 && $row['interaction_reason'] === $reason && (int) $row['is_first_contact'] === 0);
}
t('existing-customer interactions never create customers', $cnt('customers') === $c0);
$now = $crow($C1);
t("the customer's original acquisition is untouched (source, platform, campaign, channel, time)", $now['original_source_id'] === $snap['original_source_id'] && $now['original_social_platform_id'] === $snap['original_social_platform_id'] && $now['original_campaign_id'] === $snap['original_campaign_id'] && $now['first_acquisition_channel'] === $snap['first_acquisition_channel'] && $now['first_acquisition_at'] === $snap['first_acquisition_at']);
t('a campaign response is re-engagement, not an acquisition (counts as an interaction only)', (int) $db->query("SELECT COUNT(*) FROM interactions WHERE customer_id=$C1 AND interaction_reason='campaign_response' AND is_first_contact=1")->fetchColumn() === 0);
t('missing reason rejected', ix($REC, $REC_B, $recEx(['reason' => '']))['status'] === 422);
t('"first_contact" cannot be posted for an existing customer', ix($REC, $REC_B, $recEx(['reason' => 'first_contact']))['status'] === 422);
t('unknown reason rejected', ix($REC, $REC_B, $recEx(['reason' => 'because']))['status'] === 422);
$n = $cnt('interactions');
foreach (['' => 'missing', 'abc' => 'non-numeric', '0' => 'zero', '99999999' => 'unknown', (string) $ARCH => 'archived', '1 OR 1=1' => 'SQL injection'] as $v => $label) {
    t("customer id $label rejected on the server", ix($REC, $REC_B, $recEx(['customer_id' => (string) $v]))['status'] === 422);
}
t('array-valued customer id rejected', ix($REC, $REC_B, $recEx(['customer_id' => [$C1, $C2]]))['status'] === 422);
t('no interaction saved for any invalid customer id', $cnt('interactions') === $n);
$r = ix($REC, $REC_B, $recEx(['customer_id' => $ARCH]));
t('archived customer message does not leak who they are', !str_contains($r['body'], 'Archived Four') && str_contains($r['body'], 'Archived or unknown customers cannot be used'));
t('follow-up appointment list is available to entry staff for active customers', count(json_decode($CALL->req('GET', "/customers/$C1/appointments")['body'], true)['appointments']) >= 1);
t('...but not for archived customers', $CALL->req('GET', "/customers/$ARCH/appointments")['status'] === 404);

// ------------------------------------------------------------------ G. atomicity
section('G. Atomic rollback');
$snapCounts = fn() => [$cnt('customers'), $cnt('interactions'), $cnt('appointments'), $cnt('appointment_events'), $cnt('interaction_appointments'), (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('customer.create','interaction.create','appointment.create')")->fetchColumn(), (int) $db->query('SELECT next_value FROM customer_code_counter')->fetchColumn()];
$tok = bin2hex(random_bytes(16)); $before = $snapCounts();
$db->exec("CREATE TRIGGER trg_test_fail_appt BEFORE INSERT ON appointment_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'");
$r = ix($CALL, $CALL_B, $newCust(['name' => 'Newcomer Atomic', 'service_id' => $svc['Filling'], 'booking' => 'confirmed', 'appointment_at' => $later()]), $tok);
$db->exec('DROP TRIGGER trg_test_fail_appt');
t('failure at the LAST step (appointment history) -> generic 500, no details leaked', $r['status'] === 500 && !preg_match('/SQLSTATE|forced failure|trigger|PDO/i', $r['body']));
t('customer, interaction, appointment, history, links, audit rows and the ID counter were all rolled back', $snapCounts() === $before);
$r = ix($CALL, $CALL_B, $newCust(['name' => 'Newcomer Atomic', 'service_id' => $svc['Filling'], 'booking' => 'confirmed', 'appointment_at' => $later()]), $tok);
$after = $snapCounts();
t('the same form can be re-submitted after the failure: exactly one of each is created', $r['status'] === 302 && $after[0] === $before[0] + 1 && $after[1] === $before[1] + 1 && $after[2] === $before[2] + 1 && $after[3] === $before[3] + 1 && $after[6] === $before[6] + 1);
$before = $snapCounts(); $tok2 = bin2hex(random_bytes(16));
$db->exec("CREATE TRIGGER trg_test_fail_int BEFORE INSERT ON interactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'");
$r = ix($REC, $REC_B, $newCust(['name' => 'Newcomer Atomic Two', 'used' => 'used', 'service_id' => $svc['Teeth cleaning']]), $tok2);
$db->exec('DROP TRIGGER trg_test_fail_int');
t('failure while inserting the interaction -> the just-created customer is rolled back too', $r['status'] === 500 && $snapCounts() === $before && (int) $db->query("SELECT COUNT(*) FROM customers WHERE name='Newcomer Atomic Two'")->fetchColumn() === 0);

// ------------------------------------------------------------------ H. duplicate submissions
section('H. Duplicate submissions');
$tokA = bin2hex(random_bytes(16)); $b = $snapCounts();
$r1 = ix($CALL, $CALL_B, $callEx(['service_id' => $svc['Extraction']]), $tokA); $r2 = ix($CALL, $CALL_B, $callEx(['service_id' => $svc['Extraction']]), $tokA); $a = $snapCounts();
t('double submit (existing customer + booking): one interaction, one appointment', iid($r1) === iid($r2) && iid($r1) > 0 && $a[1] === $b[1] + 1 && $a[2] === $b[2] + 1 && $a[3] === $b[3] + 1);
t('the repeat says it was already recorded', str_contains($CALL->req('GET', $r2['loc'])['body'], 'already recorded'));
$tokB = bin2hex(random_bytes(16)); $b = $snapCounts(); $nf = $newCust(['name' => 'Newcomer Twice', 'booking' => 'confirmed', 'appointment_at' => $later(), 'service_id' => $svc['Filling']]);
$r1 = ix($CALL, $CALL_B, $nf, $tokB); $r2 = ix($CALL, $CALL_B, $nf, $tokB); $a = $snapCounts();
t('double submit (new customer): one customer, one interaction, one appointment', iid($r1) === iid($r2) && $a[0] === $b[0] + 1 && $a[1] === $b[1] + 1 && $a[2] === $b[2] + 1);
t("another user cannot replay someone else's form token", ix($SOC, $SOC_B, $socEx(), $tokA)['status'] === 403);
$tokC = bin2hex(random_bytes(16)); $b = $snapCounts(); $cl = []; for ($k = 0; $k < 4; $k++) { $cl[] = $login('p4_call', $P4); }
$mh = curl_multi_init(); $hs = [];
foreach ($cl as $c) {
    $cs = tokens($c->req('GET', "$CALL_B/new")['body'])[0];
    $ch = curl_init($c->base . $CALL_B);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($callEx(['service_id' => $svc['Filling']]) + ['_csrf' => $cs, 'form_token' => $tokC, 'ack_ids' => '']), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $c->jar, CURLOPT_COOKIEJAR => $c->jar]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $act); curl_multi_select($mh, 1); } while ($act > 0);
$ok = 0; foreach ($hs as $ch) { if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 302) $ok++; } $a = $snapCounts();
t('4 simultaneous identical submissions: exactly one interaction and one appointment', $a[1] === $b[1] + 1 && $a[2] === $b[2] + 1 && $ok === 4);
t('the database itself forbids a second appointment for the same creating interaction', (int) $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='babi_test' AND table_name='appointments' AND column_name='origin_interaction_id' AND non_unique=0")->fetchColumn() === 1);
t('every appointment has at most one creating interaction', (int) $db->query('SELECT COUNT(*) FROM (SELECT origin_interaction_id FROM appointments WHERE origin_interaction_id IS NOT NULL GROUP BY origin_interaction_id HAVING COUNT(*) > 1) x')->fetchColumn() === 0);
t('no appointment exists for an inquiry (not booked)', (int) $db->query("SELECT COUNT(*) FROM appointments a JOIN interactions i ON i.id=a.origin_interaction_id WHERE i.booking_outcome IN ('not_booked','not_applicable') OR i.channel='reception'")->fetchColumn() === 0);

// ------------------------------------------------------------------ I. permissions
section('I. Roles, channels and visibility');
t('receptionist cannot open the telephone or DM forms', $REC->req('GET', "$CALL_B/new")['status'] === 403 && $REC->req('GET', "$SOC_B/new")['status'] === 403);
t('call operator cannot open the reception or DM forms', $CALL->req('GET', "$REC_B/new")['status'] === 403 && $CALL->req('GET', "$SOC_B/new")['status'] === 403);
t('social tracker cannot open the reception or telephone forms', $SOC->req('GET', "$REC_B/new")['status'] === 403 && $SOC->req('GET', "$CALL_B/new")['status'] === 403);
$n = $cnt('interactions'); $tk = $REC->csrf('/reception');
t('cross-channel POST is refused (receptionist -> telephone route)', $REC->req('POST', $CALL_B, $callEx(['_csrf' => $tk, 'form_token' => bin2hex(random_bytes(16))]))['status'] === 403 && $cnt('interactions') === $n);
t('administrators have no create form (they review and correct)', $ADM->req('GET', "$REC_B/new")['status'] === 403 && $ADM->req('GET', "$CALL_B/new")['status'] === 403 && $ADM->req('GET', "$SOC_B/new")['status'] === 403);
$mk = $MKT->csrf('/marketing');
foreach (["$REC_B/new", "$CALL_B/new", "$SOC_B/new", '/interactions', "/interactions/$R1", "/interactions/$R1/edit", "/customers/$C1/appointments"] as $p) { t("marketing GET $p -> 403", $MKT->req('GET', $p)['status'] === 403); }
t('marketing cannot POST interactions or edits', $MKT->req('POST', $REC_B, $recEx(['_csrf' => $mk, 'form_token' => bin2hex(random_bytes(16))]))['status'] === 403 && $MKT->req('POST', "/interactions/$R1/edit", ['_csrf' => $mk, 'notes' => 'x'])['status'] === 403);
foreach (["$REC_B/new", '/interactions', "/interactions/$R1"] as $p) { t("anonymous $p -> login", (new Client())->req('GET', $p)['loc'] === '/login'); }
// scoping
$mkI = function (int $cust, string $channel, array $extra = []) use ($db, $adminId) {
    $platform = $channel === 'social_dm' ? 1 : 'NULL';
    $db->exec("INSERT INTO interactions (customer_id,channel,interaction_reason,interaction_at,social_platform_id,recorded_by_user_id,booking_outcome,service_usage_outcome) VALUES ($cust,'$channel','another_service',NOW(),$platform,$adminId," . ($channel === 'reception' ? "'not_applicable','not_used'" : "'not_booked','not_applicable'") . ')');
    return (int) $db->lastInsertId();
};
$CR = $mkCust('BDC-420001', 'Rec Only Cust', null); $CP = $mkCust('BDC-420002', 'Call Only Cust', null); $CD = $mkCust('BDC-420003', 'DM Only Cust', null);
$IR = $mkI($CR, 'reception'); $IP = $mkI($CP, 'phone'); $ID = $mkI($CD, 'social_dm');
$lr = $REC->req('GET', '/interactions')['body']; $lc = $CALL->req('GET', '/interactions')['body']; $ls = $SOC->req('GET', '/interactions')['body']; $la = $ADM->req('GET', '/interactions')['body'];
t('receptionist list shows only reception interactions', str_contains($lr, 'Rec Only Cust') && !str_contains($lr, 'Call Only Cust') && !str_contains($lr, 'DM Only Cust'));
t('call operator list shows only telephone interactions', str_contains($lc, 'Call Only Cust') && !str_contains($lc, 'Rec Only Cust') && !str_contains($lc, 'DM Only Cust'));
t('social tracker list shows only DM interactions', str_contains($ls, 'DM Only Cust') && !str_contains($ls, 'Rec Only Cust') && !str_contains($ls, 'Call Only Cust'));
t('administrator sees every channel and can filter', str_contains($la, 'Rec Only Cust') && str_contains($la, 'Call Only Cust') && str_contains($la, 'DM Only Cust') && !str_contains($ADM->req('GET', '/interactions?channel=phone')['body'], 'Rec Only Cust'));
t("another channel's interaction detail is 404 for staff, 200 for the administrator", $REC->req('GET', "/interactions/$IP")['status'] === 404 && $CALL->req('GET', "/interactions/$IR")['status'] === 404 && $ADM->req('GET', "/interactions/$IP")['status'] === 200 && $REC->req('GET', "/interactions/$IR")['status'] === 200);
t("another channel's interaction cannot be edited by staff (404)", $REC->req('GET', "/interactions/$IP/edit")['status'] === 404 && $REC->req('POST', "/interactions/$IP/edit", ['_csrf' => $REC->csrf('/reception'), 'notes' => 'x'])['status'] === 404);
t('list is paginated and clamps page numbers', str_contains($ADM->req('GET', '/interactions?page=999')['body'], 'page') && $ADM->req('GET', '/interactions?page=abc')['status'] === 200);
// immutability + mass assignment
$r = ix($REC, $REC_B, $recEx(['channel' => 'phone', 'recorded_by_user_id' => $adminId, 'is_first_contact' => 1, 'created_at' => '2001-01-01 00:00:00', 'id' => 1, 'booking_outcome' => 'confirmed', 'customer_mode' => 'existing'])); $row = $irow(iid($r));
t('mass-assignment of channel / recorder / flags / ids / outcome is ignored', $r['status'] === 302 && $row['channel'] === 'reception' && (int) $row['recorded_by_user_id'] === $uid('p4_rec') && (int) $row['is_first_contact'] === 0 && $row['created_at'] > '2020-01-01' && (int) $row['id'] !== 1 && $row['booking_outcome'] === 'not_applicable');
t('interaction channel cannot be changed (database trigger)', str_contains((string) throws($db, "UPDATE interactions SET channel='phone' WHERE id=$IR"), 'immutable'));
t('reception rows cannot carry bookings; phone rows cannot carry usage (database CHECKs)', throws($db, "UPDATE interactions SET booking_outcome='confirmed' WHERE id=$IR") !== null && throws($db, "UPDATE interactions SET service_usage_outcome='used' WHERE id=$IP") !== null);

// ------------------------------------------------------------------ J. editing and the 24-hour rule
section('J. Editing and the 24-hour rule');
$r = ix($REC, $REC_B, $recEx(['notes' => 'first draft note'])); $E = iid($r);
$ed = fn(Client $c, int $id, array $f, bool $csrf = true) => $c->req('POST', "/interactions/$id/edit", $f + ($csrf ? ['_csrf' => tokens($c->req('GET', "/interactions/$id/edit")['body'])[0]] : []));
t('edit form opens for the recorder inside the window', $REC->req('GET', "/interactions/$E/edit")['status'] === 200 && str_contains($REC->req('GET', "/interactions/$E")['body'], '/edit'));
$r = $ed($REC, $E, ['notes' => 'corrected note', 'service_id' => $svc['Filling'], 'used' => 'used']); $row = $irow($E);
t('recorder can correct notes and service within 24 hours', $r['status'] === 302 && $row['notes'] === 'corrected note' && (int) $row['service_id'] === $svc['Filling']);
t('channel, customer, reason, recorder and first-contact flag are unchanged by an edit', $row['channel'] === 'reception' && (int) $row['customer_id'] === $C1 && $row['interaction_reason'] === 'another_service' && (int) $row['recorded_by_user_id'] === $uid('p4_rec') && (int) $row['is_first_contact'] === 0);
$ed($REC, $E, ['notes' => 'attempt', 'service_id' => $svc['Filling'], 'used' => 'used', 'channel' => 'phone', 'customer_id' => $C2, 'booking_outcome' => 'confirmed', 'recorded_by_user_id' => $adminId]); $row = $irow($E);
t('channel / customer / booking fields posted to the edit form are ignored', $row['channel'] === 'reception' && (int) $row['customer_id'] === $C1 && $row['booking_outcome'] === 'not_applicable');
$u = $audit('interaction.update');
$r = $ed($REC, $E, ['notes' => 'note with secret-note-text again', 'service_id' => $svc['Filling'], 'used' => 'used']);
$au = $db->query("SELECT * FROM audit_logs WHERE action='interaction.update' AND entity_id=$E ORDER BY id DESC LIMIT 1")->fetch();
t('edits are audited with the changed field names, without the note text', $audit('interaction.update') === $u + 1 && str_contains($au['new_values'], 'changed_fields') && str_contains($au['new_values'], 'notes') && !str_contains($au['old_values'] . $au['new_values'], 'secret-note-text') && str_contains($au['new_values'], '"admin_override":false'));
t('no-op edit writes no audit event', $ed($REC, $E, ['notes' => 'note with secret-note-text again', 'service_id' => $svc['Filling'], 'used' => 'used'])['status'] === 302 && $audit('interaction.update') === $u + 1);
t('reception usage: used -> not_used needs a reason', $ed($REC, $E, ['notes' => 'n', 'service_id' => $svc['Filling'], 'used' => 'not_used'])['status'] === 422);
$r = $ed($REC, $E, ['notes' => 'n', 'service_id' => $svc['Filling'], 'used' => 'not_used', 'non_conversion_reason_id' => $rsn['Pricing']]); $row = $irow($E);
t('reception usage corrected with a reason', $r['status'] === 302 && $row['service_usage_outcome'] === 'not_used' && (int) $row['non_conversion_reason_id'] === $rsn['Pricing']);
$r = $ed($REC, $E, ['notes' => 'n', 'service_id' => '', 'used' => 'used']); t('...and "used" again needs a service', $r['status'] === 422);
$r = $ed($REC, $E, ['notes' => 'n', 'service_id' => $svc['Filling'], 'used' => 'used']); $row = $irow($E);
t('switching back to "used" clears the non-conversion reason', $row['service_usage_outcome'] === 'used' && $row['non_conversion_reason_id'] === null);
t('edit without CSRF rejected', $ed($REC, $E, ['notes' => 'csrf'], false)['status'] === 403 && $irow($E)['notes'] !== 'csrf');
t('another receptionist cannot edit it, even inside the window', $REC2->req('GET', "/interactions/$E/edit")['status'] === 403 && $ed($REC2, $E, ['notes' => 'hijack'], false)['status'] === 403);
t('nonexistent interaction -> 404', $REC->req('GET', '/interactions/99999999/edit')['status'] === 404 && $REC->req('GET', '/interactions/abc/edit')['status'] === 404);
// phone edit with an appointment
$P = $P1;
$r = $ed($CALL, $P, ['notes' => 'call note', 'service_id' => $svc['Filling'], 'interaction_campaign_id' => '']); $row = $irow($P);
t('call operator can edit notes of their own interaction', $r['status'] === 302 && $row['notes'] === 'call note' && $row['booking_outcome'] === 'confirmed');
t('the service cannot be changed once an appointment was booked from the interaction', $ed($CALL, $P, ['notes' => 'x', 'service_id' => $svc['Root canal']])['status'] === 422 && (int) $irow($P)['service_id'] === $svc['Filling']);
t('booking outcome cannot be edited (appointment stays as booked)', $ed($CALL, $P, ['notes' => 'call note', 'service_id' => $svc['Filling'], 'booking' => 'not_booked'])['status'] === 302 && $irow($P)['booking_outcome'] === 'confirmed' && $db->query("SELECT status FROM appointments WHERE origin_interaction_id=$P")->fetchColumn() === 'confirmed');
t('DM platform can be corrected, but not removed', $ed($SOC, $S1, ['notes' => '', 'service_id' => $svc['Teeth whitening'], 'dm_platform_id' => $plat['Facebook']])['status'] === 302 && (int) $irow($S1)['social_platform_id'] === $plat['Facebook'] && $ed($SOC, $S1, ['notes' => '', 'service_id' => $svc['Teeth whitening'], 'dm_platform_id' => ''])['status'] === 422);
// the 24-hour window
$db->exec("UPDATE interactions SET created_at = NOW() - INTERVAL 25 HOUR WHERE id=$E");
$d0 = $audit('interaction.edit_denied'); $before = $irow($E);
$r = $REC->req('GET', "/interactions/$E/edit");
t('outside 24 hours the recorder is refused (403) with an explanation', $r['status'] === 403 && str_contains($r['body'], '24-hour edit window has passed'));
t('the POST is refused too and nothing changes', $ed($REC, $E, ['notes' => 'late edit', 'service_id' => $svc['Filling'], 'used' => 'used'], false)['status'] === 403 && $irow($E)['notes'] === $before['notes']);
$r = $REC->req('POST', "/interactions/$E/edit", ['_csrf' => $REC->csrf('/reception'), 'notes' => 'late edit with csrf', 'service_id' => $svc['Filling'], 'used' => 'used']);
t('...even with a valid CSRF token', $r['status'] === 403 && $irow($E)['notes'] === $before['notes'] && $audit('interaction.edit_denied') >= $d0 + 2);
$d = $REC->req('GET', "/interactions/$E")['body'];
t('the detail page shows no Edit button and explains why', !str_contains($d, "/interactions/$E/edit") && str_contains($d, 'edit window has passed'));
$r = $ed($ADM, $E, ['notes' => 'admin correction', 'service_id' => $svc['Filling'], 'used' => 'used']);
$au = $db->query("SELECT new_values FROM audit_logs WHERE action='interaction.update' AND entity_id=$E ORDER BY id DESC LIMIT 1")->fetchColumn();
t('the administrator may still correct it, flagged as an admin override in the audit trail', $r['status'] === 302 && $irow($E)['notes'] === 'admin correction' && str_contains((string) $au, '"admin_override":true'));
$db->exec("UPDATE interactions SET created_at = NOW() - INTERVAL 2 HOUR WHERE id=$P"); $db->exec("UPDATE settings SET setting_value='1' WHERE setting_key='entry_edit_window_hours'");
t('the window comes from the setting (1 hour -> a 2-hour-old interaction is locked)', $CALL->req('GET', "/interactions/$P/edit")['status'] === 403);
$db->exec("UPDATE settings SET setting_value='24' WHERE setting_key='entry_edit_window_hours'");
t('...and is editable again when the setting is 24 hours', $CALL->req('GET', "/interactions/$P/edit")['status'] === 200);

// ------------------------------------------------------------------ K. security
section('K. CSRF, SQL injection, XSS');
$n = $cnt('interactions'); $a = $cnt('appointments');
t('create without a CSRF token rejected on all three channels', ix($REC, $REC_B, $recEx(), null, false)['status'] === 403 && ix($CALL, $CALL_B, $callEx(), null, false)['status'] === 403 && ix($SOC, $SOC_B, $socEx(), null, false)['status'] === 403);
t('nothing saved by the CSRF failures', $cnt('interactions') === $n && $cnt('appointments') === $a);
$bad = 0;
foreach ([
    ['service_id', "1 OR 1=1", []], ['service_id', "1; DROP TABLE interactions; --", []], ['interaction_campaign_id', "1' OR '1'='1", []], ['reason', "x' OR 1=1 --", []],
    ['non_conversion_reason_id', '1 UNION SELECT 1', ['booking' => 'not_booked', 'appointment_at' => '']], ['interaction_at', "2026-01-01'; DROP TABLE interactions;--", []],
    ['ad_source_id', '1 OR 1=1', ['reason' => 'campaign_response']], ['followup_appointment_id', '0 OR 1=1', ['reason' => 'appointment_followup', 'booking' => 'not_applicable', 'appointment_at' => '']],
] as [$f, $v, $ctx]) {
    $r = ix($CALL, $CALL_B, $callEx([$f => $v] + $ctx));
    if ($r['status'] !== 422) $bad++;
}
t('SQL injection strings in every id/date/enum field are rejected cleanly (422, never 500)', $bad === 0);
$r = ix($CALL, $CALL_B, $callEx(['booking' => 'not_booked', 'appointment_at' => '', 'non_conversion_reason_id' => $rsn['Pricing'], 'notes' => "Robert'); DROP TABLE interactions;--"])); $row = $irow(iid($r));
t('SQL in free-text notes is stored as plain text and harmless', $r['status'] === 302 && $row['notes'] === "Robert'); DROP TABLE interactions;--" && $cnt('interactions') > $n);
t('core tables intact', (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='babi_test' AND table_name IN ('interactions','appointments','customers')")->fetchColumn() === 3);
t('XSS in notes is escaped in the edit form as well', str_contains($REC->req('GET', "/interactions/$XSS/edit")['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
t('XSS in a customer name is escaped in the interaction list', (function () use ($db, $mkCust, $mkI, $ADM) { $c = $mkCust('BDC-430001', '<img src=x onerror=alert(1)>', null); $mkI($c, 'phone'); $b = $ADM->req('GET', '/interactions')['body']; return str_contains($b, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($b, '<img src=x'); })());
t('interaction pages send CSP + no-store', str_contains($REC->req('GET', '/interactions')['headers'], 'Content-Security-Policy') && str_contains($REC->req('GET', '/interactions')['headers'], 'no-store'));
foreach (['/interactions' => $la, "/interactions/$IR" => $ADM->req('GET', "/interactions/$IR")['body'], "/interactions/$E/edit" => $ADM->req('GET', "/interactions/$E/edit")['body']] as $p => $h) {
    t("$p: no inline script/style/handlers", !preg_match('/<script(?![^>]*\bsrc=)|\sstyle=|\son[a-z]+=/i', $h) && str_contains($h, 'name="viewport"'));
}

// ------------------------------------------------------------------ L. audit
section('L. Audit trail without customer PII');
foreach (['interaction.create', 'appointment.create', 'interaction.update', 'interaction.edit_denied', 'customer.create', 'customer.duplicate_override', 'auth.access_denied'] as $ev) { t("audit event recorded: $ev", $audit($ev) >= 1); }
$all = (string) $db->query("SELECT GROUP_CONCAT(COALESCE(old_values,''),COALESCE(new_values,'')) FROM audit_logs WHERE action LIKE 'interaction.%' OR action LIKE 'appointment.%' OR action LIKE 'customer.%'")->fetchColumn();
t('no names, phone numbers or note text anywhere in the audit payloads', !preg_match('/Zelalem|Newcomer|Rec Only|0912345678|0933|secret-note-text|corrected note|Robert/', $all));
t('interaction.create stores structure only (ids, channel, outcomes)', (bool) preg_match('/"channel":"phone".*"booking_outcome":"confirmed"/s', (string) $db->query("SELECT new_values FROM audit_logs WHERE action='interaction.create' AND entity_id=$P1")->fetchColumn()));
t('every interaction.create / appointment.create names the acting user', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('interaction.create','appointment.create') AND user_id IS NULL")->fetchColumn() === 0);
t('every appointment created by the questionnaires has an audit event', (int) $db->query("SELECT COUNT(*) FROM appointments a WHERE a.origin_interaction_id IN (SELECT entity_id FROM audit_logs WHERE action='interaction.create') AND NOT EXISTS (SELECT 1 FROM audit_logs l WHERE l.action='appointment.create' AND l.entity_id=a.id)")->fetchColumn() === 0);

finish('Phase 4 suite');
