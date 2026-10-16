<?php
declare(strict_types=1);
// Phase 6 suite: service, campaign and appointment management.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $P6 = 'Walnut-Meadow-6284';
$role = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$src = array_column($db->query('SELECT name,id FROM acquisition_sources')->fetchAll(), 'id', 'name');
$plat = array_column($db->query('SELECT name,id FROM social_platforms')->fetchAll(), 'id', 'name');
$adminId = (int) $db->query("SELECT id FROM users WHERE username='boss'")->fetchColumn();
$hash = password_hash($P6, PASSWORD_BCRYPT, ['cost' => 12]);
foreach ([['p6_rec', 'receptionist'], ['p6_call', 'call_operator'], ['p6_mkt', 'marketing']] as [$u, $r]) {
    $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password, password_changed_at) VALUES (?,?,?,?,0,NOW())')->execute([ucfirst($r) . ' Six', $u, $hash, $role[$r]]);
}
$login = function (string $u, string $p) { $c = new Client(); $c->login($u, $p); return $c; };
$REC = $login('p6_rec', $P6); $CALL = $login('p6_call', $P6); $MKT = $login('p6_mkt', $P6); $ADM = $login('boss', $ADMIN_PW); $ANON = new Client();
$uid = fn(string $u) => (int) $db->query("SELECT id FROM users WHERE username='$u'")->fetchColumn();
$auditCount = fn(string $action) => (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = " . $db->quote($action))->fetchColumn();

section('A. Migration and permissions');
t('campaigns.view permission exists for admin and marketing only', array_column($db->query("SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code='campaigns.view' ORDER BY r.code")->fetchAll(), 'code') === ['admin', 'marketing']);
foreach (['/appointments', '/campaigns', '/admin/services'] as $p) { $r = $ANON->req('GET', $p); t("anonymous $p -> login", $r['status'] === 302 && $r['loc'] === '/login'); }
foreach (['/admin/services', '/admin/services/new', '/campaigns/new', '/appointments'] as $p) { t("marketing GET $p -> 403", $MKT->req('GET', $p)['status'] === 403); }
t('marketing can view /campaigns', $MKT->req('GET', '/campaigns')['status'] === 200);
foreach (['/admin/services', '/admin/services/new', '/campaigns', '/campaigns/new'] as $p) { t("reception GET $p -> 403", $REC->req('GET', $p)['status'] === 403); }
t('reception can open /appointments', $REC->req('GET', '/appointments')['status'] === 200);
foreach (['/admin/services', '/admin/services/new', '/campaigns', '/campaigns/new', '/appointments'] as $p) { t("admin GET $p -> 200", $ADM->req('GET', $p)['status'] === 200); }
t('admin navigation lists Appointments, Campaigns, Services', (function () use ($ADM) { $b = $ADM->req('GET', '/admin')['body']; return str_contains($b, 'href="/appointments"') && str_contains($b, 'href="/campaigns"') && str_contains($b, 'href="/admin/services"'); })());
t('marketing navigation shows Campaigns but not Appointments/Services', (function () use ($MKT) { $b = $MKT->req('GET', '/marketing')['body']; return str_contains($b, 'href="/campaigns"') && !str_contains($b, 'href="/appointments"') && !str_contains($b, 'href="/admin/services"'); })());

section('B. CSRF on every new POST');
foreach (['/admin/services', '/campaigns'] as $p) { t("POST $p without token -> 403", $ADM->req('POST', $p, ['name' => 'x'])['status'] === 403); }
t('POST appointment status without token -> 403', $ADM->req('POST', '/appointments/1/status', ['status' => 'confirmed'])['status'] === 403);
t('POST appointment reschedule without token -> 403', $ADM->req('POST', '/appointments/1/reschedule', ['appointment_at' => '2030-01-01T10:00'])['status'] === 403);

section('C. Services');
$r = $ADM->post('/admin/services/new', '/admin/services', ['name' => 'Teeth Whitening P6', 'description' => 'Cosmetic']);
t('create service redirects to list', $r['status'] === 302 && $r['loc'] === '/admin/services');
$sid = (int) $db->query("SELECT id FROM services WHERE name='Teeth Whitening P6'")->fetchColumn();
t('service stored and active', $sid > 0 && (int) $db->query("SELECT is_active FROM services WHERE id=$sid")->fetchColumn() === 1);
t('duplicate name rejected (422)', $ADM->post('/admin/services/new', '/admin/services', ['name' => 'Teeth Whitening P6'])['status'] === 422);
t('empty name rejected (422)', $ADM->post('/admin/services/new', '/admin/services', ['name' => '  '])['status'] === 422);
t('overlong description rejected (422)', $ADM->post('/admin/services/new', '/admin/services', ['name' => 'Long P6', 'description' => str_repeat('x', 256)])['status'] === 422);
$r = $ADM->post("/admin/services/$sid/edit", "/admin/services/$sid", ['name' => 'Teeth Whitening P6b', 'description' => '']);
t('edit service', $r['status'] === 302 && $db->query("SELECT name FROM services WHERE id=$sid")->fetchColumn() === 'Teeth Whitening P6b' && $db->query("SELECT description FROM services WHERE id=$sid")->fetchColumn() === null);
t('XSS in service name is escaped in the list', (function () use ($ADM) { $ADM->post('/admin/services/new', '/admin/services', ['name' => '<script>alert(1)</script>P6']); $b = $ADM->req('GET', '/admin/services')['body']; return !str_contains($b, '<script>alert(1)</script>') && str_contains($b, '&lt;script&gt;'); })());
// history survives deactivation
$custA = function (string $code, string $name) use ($db, $adminId, $src) { $db->prepare("INSERT INTO customers (customer_code,name,phone,phone_normalized,original_source_id,first_acquisition_channel,first_acquisition_at,created_by_user_id) VALUES (?,?,'0911000000','0911000000',?, 'phone', NOW() - INTERVAL 10 DAY, ?)")->execute([$code, $name, $src['Friend or family referral'], $adminId]); return (int) $db->lastInsertId(); };
$made = [];
$mkAppt = function (int $cust, int $svc, string $status, ?string $at, int $by) use ($db, &$made) {
    $made[$cust] = ($made[$cust] ?? 0) + 1;
    $bo = in_array($status, ['requested', 'confirmed'], true) ? $status : 'confirmed';
    $db->prepare("INSERT INTO interactions (customer_id,channel,interaction_reason,is_first_contact,interaction_at,service_id,booking_outcome,recorded_by_user_id) VALUES (?, 'phone','another_service',0,NOW() - INTERVAL 1 DAY,?,?,?)")->execute([$cust, $svc, $bo, $by]);
    $iid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO appointments (customer_id,service_id,appointment_at,status,origin_interaction_id,created_by_user_id) VALUES (?,?,?,?,?,?)')->execute([$cust, $svc, $at, $status, $iid, $by]);
    $aid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO appointment_events (appointment_id,from_status,to_status,interaction_id,user_id) VALUES (?,NULL,?,?,?)')->execute([$aid, $status, $iid, $by]);
    $db->prepare("INSERT INTO interaction_appointments (interaction_id,appointment_id,link_type) VALUES (?,?,'booked')")->execute([$iid, $aid]);
    return [$aid, $iid];
};
$C1 = $custA('BDC-610001', 'Selam Haile');
[$A0] = $mkAppt($C1, $sid, 'requested', null, $uid('p6_call'));
$r = $ADM->post('/admin/services', "/admin/services/$sid/active", []);
t('deactivate service (redirects, is_active = 0)', $r['status'] === 302 && (int) $db->query("SELECT is_active FROM services WHERE id=$sid")->fetchColumn() === 0);
t('deactivated service keeps its appointment and name', (int) $db->query("SELECT COUNT(*) FROM appointments WHERE service_id=$sid")->fetchColumn() === 1 && str_contains($ADM->req('GET', "/appointments/$A0")['body'], 'Teeth Whitening P6b'));
t('deactivated service is not offered to staff (questionnaire)', !str_contains($CALL->req('GET', '/call-operator/interactions/new')['body'], 'Teeth Whitening P6b'));
$ADM->post('/admin/services', "/admin/services/$sid/active", []);
t('reactivated service is offered again', (int) $db->query("SELECT is_active FROM services WHERE id=$sid")->fetchColumn() === 1 && str_contains($CALL->req('GET', '/call-operator/interactions/new')['body'], 'Teeth Whitening P6b'));
t('audit: service.create / update / deactivate / activate', $auditCount('service.create') >= 1 && $auditCount('service.update') >= 1 && $auditCount('service.deactivate') >= 1 && $auditCount('service.activate') >= 1);
t('no delete route exists for services', in_array($ADM->post('/admin/services', "/admin/services/$sid/delete", [])['status'], [404, 405], true));
t('unknown service id -> 404', $ADM->req('GET', '/admin/services/999999/edit')['status'] === 404);

section('D. Campaigns');
$r = $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Ramadan Offer P6', 'campaign_code' => 'RAM-P6', 'platform_id' => (string) $plat['Instagram'], 'start_date' => '2026-03-01', 'end_date' => '2026-03-30', 'status' => 'active']);
t('create campaign', $r['status'] === 302 && $r['loc'] === '/campaigns');
$cid = (int) $db->query("SELECT id FROM campaigns WHERE campaign_code='RAM-P6'")->fetchColumn();
t('campaign stored with platform, dates, status', $cid > 0 && $db->query("SELECT CONCAT(platform_id,'|',start_date,'|',end_date,'|',status) FROM campaigns WHERE id=$cid")->fetchColumn() === $plat['Instagram'] . '|2026-03-01|2026-03-30|active');
t('end before start rejected', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Bad Dates P6', 'start_date' => '2026-03-10', 'end_date' => '2026-03-01', 'status' => 'draft'])['status'] === 422);
t('invalid date rejected', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Bad Date2 P6', 'start_date' => '2026-02-30', 'status' => 'draft'])['status'] === 422);
t('duplicate code rejected', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Dup P6', 'campaign_code' => 'RAM-P6', 'status' => 'draft'])['status'] === 422);
t('bad code characters rejected', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Code P6', 'campaign_code' => 'a b;c', 'status' => 'draft'])['status'] === 422);
t('unknown platform rejected', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Plat P6', 'platform_id' => '9999', 'status' => 'draft'])['status'] === 422);
t('invalid status rejected', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Stat P6', 'status' => 'bogus'])['status'] === 422);
t('offline campaign (no platform, no code) allowed', $ADM->post('/campaigns/new', '/campaigns', ['name' => 'Billboard P6', 'status' => 'draft'])['status'] === 302 && $db->query("SELECT campaign_code FROM campaigns WHERE name='Billboard P6'")->fetchColumn() === null);
// responses + acquisition protection
$C2 = $custA('BDC-610002', 'Dawit Bekele');
$srcBefore = $db->query("SELECT CONCAT(original_source_id,'|',IFNULL(original_social_platform_id,''),'|',first_acquisition_channel,'|',first_acquisition_at) FROM customers WHERE id=$C2")->fetchColumn();
$db->prepare("INSERT INTO interactions (customer_id,channel,interaction_reason,is_first_contact,interaction_at,social_platform_id,campaign_id,booking_outcome,recorded_by_user_id) VALUES (?, 'social_dm','campaign_response',0,NOW() - INTERVAL 1 HOUR,?,?, 'not_booked', ?)")->execute([$C2, $plat['Instagram'], $cid, $uid('p6_call')]);
$r = $ADM->post("/campaigns/$cid/edit", "/campaigns/$cid", ['name' => 'Ramadan Offer P6 v2', 'campaign_code' => 'RAM-P6', 'platform_id' => (string) $plat['Facebook'], 'start_date' => '2026-03-01', 'end_date' => '2026-04-15', 'status' => 'paused']);
t('edit campaign (name, platform, end date, status)', $r['status'] === 302 && $db->query("SELECT CONCAT(name,'|',platform_id,'|',end_date,'|',status) FROM campaigns WHERE id=$cid")->fetchColumn() === 'Ramadan Offer P6 v2|' . $plat['Facebook'] . '|2026-04-15|paused');
t('campaign edit never changes the customer original acquisition source', $db->query("SELECT CONCAT(original_source_id,'|',IFNULL(original_social_platform_id,''),'|',first_acquisition_channel,'|',first_acquisition_at) FROM customers WHERE id=$C2")->fetchColumn() === $srcBefore);
t('historical interaction keeps its campaign reference and its own platform', (int) $db->query("SELECT campaign_id FROM interactions WHERE customer_id=$C2 AND interaction_reason='campaign_response'")->fetchColumn() === $cid && (int) $db->query("SELECT social_platform_id FROM interactions WHERE customer_id=$C2 AND interaction_reason='campaign_response'")->fetchColumn() === $plat['Instagram']);
t('campaign with responses cannot return to draft', $ADM->post("/campaigns/$cid/edit", "/campaigns/$cid", ['name' => 'Ramadan Offer P6 v2', 'status' => 'draft'])['status'] === 422);
$r = $ADM->post("/campaigns/$cid/edit", "/campaigns/$cid", ['name' => 'Ramadan Offer P6 v2', 'campaign_code' => 'RAM-P6', 'platform_id' => (string) $plat['Facebook'], 'start_date' => '2026-03-01', 'end_date' => '2026-04-15', 'status' => 'ended']);
t('campaign can be ended', $r['status'] === 302 && $db->query("SELECT status FROM campaigns WHERE id=$cid")->fetchColumn() === 'ended');
t('list shows response count and status filter works', (function () use ($ADM) { $all = $ADM->req('GET', '/campaigns')['body']; $f = $ADM->req('GET', '/campaigns?status=ended')['body']; return str_contains($all, 'Ramadan Offer P6 v2') && str_contains($f, 'Ramadan Offer P6 v2') && !str_contains($ADM->req('GET', '/campaigns?status=active')['body'], 'Ramadan Offer P6 v2'); })());
$mb = $MKT->req('GET', '/campaigns')['body'];
t('marketing view lists campaigns without edit links or customer data', str_contains($mb, 'Ramadan Offer P6 v2') && !str_contains($mb, '/edit') && !str_contains($mb, 'Dawit') && !str_contains($mb, 'BDC-610002'));
t('audit: campaign.create / update / status_change', $auditCount('campaign.create') >= 1 && $auditCount('campaign.update') + $auditCount('campaign.status_change') >= 2 && $auditCount('campaign.status_change') >= 1);
t('XSS in campaign name is escaped', (function () use ($ADM) { $ADM->post('/campaigns/new', '/campaigns', ['name' => '<img src=x onerror=alert(1)> P6', 'status' => 'draft']); $b = $ADM->req('GET', '/campaigns')['body']; return !str_contains($b, '<img src=x') && str_contains($b, '&lt;img'); })());
t('draft campaigns are not offered in questionnaires', !str_contains($CALL->req('GET', '/call-operator/interactions/new')['body'], 'Billboard P6'));

section('E. Appointments: view, filter, detail');
$C3 = $custA('BDC-610003', 'Hana Girma');
$svcAny = (int) $db->query('SELECT id FROM services WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
$past = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 hours')->format('Y-m-d H:i:s');
$future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d H:i:s');
[$A1, $I1] = $mkAppt($C3, $svcAny, 'requested', null, $uid('p6_call'));
[$A2] = $mkAppt($C3, $svcAny, 'confirmed', $future, $uid('p6_call'));
[$A3] = $mkAppt($C3, $svcAny, 'confirmed', $past, $uid('p6_call'));
$l = $REC->req('GET', '/appointments?q=BDC-610003');
t('list filters by customer code', $l['status'] === 200 && str_contains($l['body'], "/appointments/$A1") && !str_contains($l['body'], "/appointments/$A0\""));
t('list filters by status', str_contains($REC->req('GET', '/appointments?q=BDC-610003&status=requested')['body'], "/appointments/$A1") && !str_contains($REC->req('GET', '/appointments?q=BDC-610003&status=requested')['body'], "/appointments/$A2\""));
t('list filters by service, channel and date range', (function () use ($REC, $svcAny, $A2, $future) { $d = substr($future, 0, 10); $b = $REC->req('GET', "/appointments?q=BDC-610003&service_id=$svcAny&channel=phone&from=$d&to=$d")['body']; return str_contains($b, "/appointments/$A2") && !str_contains($REC->req('GET', '/appointments?q=BDC-610003&channel=social_dm')['body'], "/appointments/$A2\""); })());
t('invalid filter values show an error, never a 500', (function () use ($REC) { foreach (['status=zzz', 'service_id=abc', 'channel=x', 'from=2026-13-40', 'to=nope', 'page=-5', "q=%27%20OR%201=1--"] as $qs) { if ($REC->req('GET', "/appointments?$qs")['status'] !== 200) return false; } return true; })());
$d = $REC->req('GET', "/appointments/$A1");
t('detail shows customer, service, status and history', $d['status'] === 200 && str_contains($d['body'], 'BDC-610003') && str_contains($d['body'], 'Created as') && str_contains($d['body'], 'Requested'));
t('detail shows the linked originating interaction', str_contains($d['body'], "#$I1") && str_contains($d['body'], 'booked here'));
t('unknown appointment -> 404', $REC->req('GET', '/appointments/999999')['status'] === 404);
t('appointments cannot be created through this module', in_array($ADM->post('/appointments', '/appointments', ['customer_id' => $C3])['status'], [404, 405], true));
t('list and detail never expose phone numbers or interaction notes', !str_contains($l['body'], '0911000000') && !str_contains($d['body'], '0911000000'));

section('F. Status changes, history and transactions');
$ev = fn(int $a) => $db->query("SELECT GROUP_CONCAT(CONCAT(IFNULL(from_status,'-'),'>',to_status) ORDER BY id SEPARATOR ',') FROM appointment_events WHERE appointment_id=$a")->fetchColumn();
$postStatus = fn(Client $c, int $a, string $to, string $at = '') => $c->post("/appointments/$a", "/appointments/$a/status", ['status' => $to] + ($at !== '' ? ['appointment_at' => $at] : []));
t('requested -> confirmed without a time is rejected (422)', $postStatus($CALL, $A1, 'confirmed')['status'] === 422);
t('rejected change leaves status and history untouched', $db->query("SELECT status FROM appointments WHERE id=$A1")->fetchColumn() === 'requested' && substr_count((string) $ev($A1), ',') === 0);
t('requested -> confirmed in the past rejected', $postStatus($CALL, $A1, 'confirmed', (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 days')->format('Y-m-d\TH:i'))['status'] === 422);
$when = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d\TH:i');
$r = $postStatus($CALL, $A1, 'confirmed', $when);
t('requested -> confirmed with a time succeeds (302)', $r['status'] === 302 && $db->query("SELECT status FROM appointments WHERE id=$A1")->fetchColumn() === 'confirmed' && substr((string) $db->query("SELECT appointment_at FROM appointments WHERE id=$A1")->fetchColumn(), 0, 16) === str_replace('T', ' ', $when));
t('history records the change once (-> requested, requested>confirmed)', $ev($A1) === '->requested,requested>confirmed');
t('history row names the acting user and no interaction', (int) $db->query("SELECT user_id FROM appointment_events WHERE appointment_id=$A1 AND to_status='confirmed'")->fetchColumn() === $uid('p6_call') && $db->query("SELECT interaction_id FROM appointment_events WHERE appointment_id=$A1 AND to_status='confirmed'")->fetchColumn() === null);
t('repeating the same change is refused and adds no history', $postStatus($CALL, $A1, 'confirmed', $when)['status'] === 422 && substr_count((string) $ev($A1), ',') === 1);
[$A6] = $mkAppt($C3, $svcAny, 'requested', null, $uid('p6_call'));
t('requested -> completed / no-show are not allowed', $postStatus($CALL, $A6, 'completed')['status'] === 422 && $postStatus($CALL, $A6, 'no_show')['status'] === 422 && $db->query("SELECT status FROM appointments WHERE id=$A6")->fetchColumn() === 'requested');
t('future confirmed appointment cannot be completed or marked no-show', $postStatus($REC, $A2, 'completed')['status'] === 422 && $postStatus($REC, $A2, 'no_show')['status'] === 422 && $db->query("SELECT status FROM appointments WHERE id=$A2")->fetchColumn() === 'confirmed');
t('future confirmed appointment can be cancelled', $postStatus($REC, $A2, 'cancelled')['status'] === 302 && $ev($A2) === '->confirmed,confirmed>cancelled');
t('cancelled is final (no reopen, no complete)', $postStatus($ADM, $A2, 'confirmed', $when)['status'] === 422 && $postStatus($ADM, $A2, 'completed')['status'] === 422);
t('past confirmed appointment: reception can mark completed', $postStatus($REC, $A3, 'completed')['status'] === 302 && $db->query("SELECT status FROM appointments WHERE id=$A3")->fetchColumn() === 'completed');
t('completed is final and a second completion adds nothing', $postStatus($REC, $A3, 'completed')['status'] === 422 && $postStatus($REC, $A3, 'no_show')['status'] === 422 && substr_count((string) $ev($A3), ',') === 1);
[$A4] = $mkAppt($C3, $svcAny, 'confirmed', $past, $uid('p6_call'));
t('past confirmed appointment can be marked no-show', $postStatus($CALL, $A4, 'no_show')['status'] === 302 && $db->query("SELECT status FROM appointments WHERE id=$A4")->fetchColumn() === 'no_show');
t('invalid status value rejected', $postStatus($ADM, $A1, 'bogus')['status'] === 422);
t('marketing cannot change appointments (403) and nothing changes', $postStatus($MKT, $A1, 'cancelled')['status'] === 403 && $db->query("SELECT status FROM appointments WHERE id=$A1")->fetchColumn() === 'confirmed');
t('origin interaction link and booked link survive every change', (int) $db->query("SELECT COUNT(*) FROM interaction_appointments WHERE appointment_id IN ($A1,$A2,$A3,$A4) AND link_type='booked'")->fetchColumn() === 4 && (int) $db->query("SELECT COUNT(*) FROM appointments WHERE id IN ($A1,$A2,$A3,$A4) AND origin_interaction_id IS NOT NULL")->fetchColumn() === 4);
t('appointment count unchanged by management (no double counting)', (int) $db->query("SELECT COUNT(*) FROM appointments WHERE customer_id=$C3")->fetchColumn() === $made[$C3]);
t('audit: appointment.status_change recorded with old and new status', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='appointment.status_change' AND entity_id='$A1' AND old_values LIKE '%requested%' AND new_values LIKE '%confirmed%'")->fetchColumn() === 1);
t('audit payloads hold no names or phone numbers', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE (action LIKE 'appointment.%' OR action LIKE 'service.%' OR action LIKE 'campaign.%') AND (CONCAT(IFNULL(old_values,''),IFNULL(new_values,'')) LIKE '%Hana%' OR CONCAT(IFNULL(old_values,''),IFNULL(new_values,'')) LIKE '%Selam%' OR CONCAT(IFNULL(old_values,''),IFNULL(new_values,'')) LIKE '%0911%')")->fetchColumn() === 0);
t('invariant: every appointment status equals its last history event', (int) $db->query("SELECT COUNT(*) FROM appointments a WHERE a.status <> (SELECT e.to_status FROM appointment_events e WHERE e.appointment_id=a.id ORDER BY e.id DESC LIMIT 1)")->fetchColumn() === 0);
// atomicity: a failing history insert must roll the status change back
$db->exec('CREATE TRIGGER trg_p6_fail BEFORE INSERT ON appointment_events FOR EACH ROW BEGIN IF NEW.to_status = \'cancelled\' AND NEW.from_status = \'requested\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'p6 forced failure\'; END IF; END');
try {
    [$A5] = $mkAppt($C3, $svcAny, 'requested', null, $uid('p6_call'));
    $r = $postStatus($CALL, $A5, 'cancelled');
    t('forced history failure -> friendly 422, no 500', $r['status'] === 422);
    t('forced history failure rolls the status change back (atomic)', $db->query("SELECT status FROM appointments WHERE id=$A5")->fetchColumn() === 'requested' && substr_count((string) $ev($A5), ',') === 0 && (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='appointment.status_change' AND entity_id='$A5'")->fetchColumn() === 0);
} finally { $db->exec('DROP TRIGGER IF EXISTS trg_p6_fail'); }

section('G. Rescheduling');
$when2 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d\TH:i');
$eventsBefore = (int) $db->query("SELECT COUNT(*) FROM appointment_events WHERE appointment_id=$A1")->fetchColumn();
$rs = fn(Client $c, int $a, string $at) => $c->post("/appointments/$a", "/appointments/$a/reschedule", ['appointment_at' => $at]);
t('reschedule a confirmed appointment', $rs($CALL, $A1, $when2)['status'] === 302 && substr((string) $db->query("SELECT appointment_at FROM appointments WHERE id=$A1")->fetchColumn(), 0, 16) === str_replace('T', ' ', $when2));
t('reschedule does not add a status event (no double counting)', (int) $db->query("SELECT COUNT(*) FROM appointment_events WHERE appointment_id=$A1")->fetchColumn() === $eventsBefore && $db->query("SELECT status FROM appointments WHERE id=$A1")->fetchColumn() === 'confirmed');
t('reschedule is audited with old and new time', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='appointment.reschedule' AND entity_id='$A1'")->fetchColumn() === 1);
t('detail page shows the reschedule in the history', str_contains($CALL->req('GET', "/appointments/$A1")['body'], 'Rescheduled'));
t('reschedule to the past rejected', $rs($CALL, $A1, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d\TH:i'))['status'] === 422);
t('reschedule with garbage rejected', $rs($CALL, $A1, 'tomorrow-ish')['status'] === 422);
t('closed appointments cannot be rescheduled', $rs($ADM, $A3, $when2)['status'] === 422 && $rs($ADM, $A2, $when2)['status'] === 422);
t('marketing cannot reschedule (403)', $rs($MKT, $A1, $when2)['status'] === 403);

section('H. Timezone: closing rule uses the application timezone');
$off = fn(string $tz) => (new DateTimeImmutable('now', new DateTimeZone($tz)));
foreach (['UTC', 'Africa/Addis_Ababa', 'America/New_York'] as $tz) {
    $zone = new DateTimeZone($tz);
    [$Ap] = $mkAppt($C3, $svcAny, 'confirmed', (new DateTimeImmutable('now', $zone))->modify('-1 hour')->format('Y-m-d H:i:s'), $uid('p6_call'));
    [$Af] = $mkAppt($C3, $svcAny, 'confirmed', (new DateTimeImmutable('now', $zone))->modify('+1 hour')->format('Y-m-d H:i:s'), $uid('p6_call'));
    $run = function (int $a) use ($tz) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/p6_child.php', (string) $a, 'completed', (string) 1], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..',
            ['DB_HOST' => 'localhost', 'DB_USER' => 'babi_app', 'DB_PASS' => (string) getenv('PW'), 'DB_NAME' => 'babi_test', 'APP_ENV' => 'testing', 'APP_TIMEZONE' => $tz, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        return json_decode(trim($o), true);
    };
    $rp = $run($Ap); $rf = $run($Af);
    t("$tz: appointment one hour ago (local) can be completed", is_array($rp) && $rp['ok'] === true && $db->query("SELECT status FROM appointments WHERE id=$Ap")->fetchColumn() === 'completed', json_encode($rp));
    t("$tz: appointment one hour ahead (local) cannot be completed", is_array($rf) && $rf['ok'] === false && $db->query("SELECT status FROM appointments WHERE id=$Af")->fetchColumn() === 'confirmed', json_encode($rf));
}

section('I. Regression guards');
t('no appointment rows were created by the management pages (only fixtures)', (int) $db->query("SELECT COUNT(*) FROM appointments WHERE created_by_user_id NOT IN (SELECT id FROM users)")->fetchColumn() === 0);
t('core tables intact', (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='babi_test' AND table_name IN ('customers','interactions','appointments','appointment_events','interaction_appointments','campaigns','services','audit_logs')")->fetchColumn() === 8);
t('new pages send CSP + no-store and no inline scripts', (function () use ($ADM, $A1) { foreach (['/appointments', "/appointments/$A1", '/campaigns', '/campaigns/new', '/admin/services', '/admin/services/new'] as $p) { $r = $ADM->req('GET', $p); if (!str_contains($r['headers'], "Content-Security-Policy: default-src 'self'") || !str_contains($r['headers'], 'no-store') || preg_match('/<script(?![^>]*\bsrc=)|<[^>]*\s(on[a-z]+|style)=/i', $r['body'])) return false; } return true; })());

finish('Phase 6 suite');
