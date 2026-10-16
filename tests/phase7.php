<?php
declare(strict_types=1);
// Phase 7 suite: analytics. Fixtures live in March 2025 and are queried with a custom range, so they cannot mix with
// the NOW()-based data of other suites; exact expected numbers are derived by hand in the comments below.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $P7 = 'Cedar-Willow-7391';
$role = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$hash = password_hash($P7, PASSWORD_BCRYPT, ['cost' => 12]);
foreach ([['p7_rec', 'receptionist'], ['p7_call', 'call_operator'], ['p7_soc', 'social_tracker'], ['p7_mkt', 'marketing']] as [$u, $r]) {
    $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password, password_changed_at) VALUES (?,?,?,?,0,NOW())')->execute([ucfirst($r) . ' Seven', $u, $hash, $role[$r]]);
}
$login = function (string $u, string $p) { $c = new Client(); $c->login($u, $p); return $c; };
$REC = $login('p7_rec', $P7); $CALL = $login('p7_call', $P7); $SOC = $login('p7_soc', $P7); $MKT = $login('p7_mkt', $P7); $ADM = $login('boss', $ADMIN_PW); $ANON = new Client();
$uid = fn(string $u) => (int) $db->query("SELECT id FROM users WHERE username='$u'")->fetchColumn();
$adminId = $uid('boss'); $callId = $uid('p7_call');
$srcRows = $db->query('SELECT id, name FROM acquisition_sources ORDER BY id LIMIT 2')->fetchAll(); [$S1, $S2] = [$srcRows[0], $srcRows[1]];
$IG = (int) $db->query("SELECT id FROM social_platforms WHERE name='Instagram'")->fetchColumn();
$svc = $db->query('SELECT id, name FROM services WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(); [$SX, $SY] = [$svc[0], $svc[1]];

section('A. Fixture isolation');
$inWin = fn(string $t, string $c) => (int) $db->query("SELECT COUNT(*) FROM $t WHERE $c >= '2025-01-29' AND $c < '2025-04-02'")->fetchColumn();
t('March 2025 window is empty before fixtures', $inWin('customers', 'first_acquisition_at') + $inWin('interactions', 'interaction_at') + $inWin('appointments', 'created_at') === 0);

// ---- fixtures ------------------------------------------------------------------------------------------------
$k = $db->prepare("INSERT INTO campaigns (name, platform_id, campaign_code, start_date, end_date, status) VALUES (?,?,?,?,?,?)");
$k->execute(['P7 Spring Promo', $IG, 'P7-SPRING', '2025-03-01', '2025-03-31', 'active']); $K1 = (int) $db->lastInsertId();
$k->execute(['P7 Unused Draft', null, 'P7-DRAFT', null, null, 'draft']); $K2 = (int) $db->lastInsertId();
$cust = function (string $code, string $name, array $src, string $ch, string $at, ?int $plat = null, ?int $camp = null, string $status = 'active', ?int $mergedInto = null) use ($db, $adminId) {
    $db->prepare("INSERT INTO customers (customer_code,name,phone,phone_normalized,original_source_id,original_social_platform_id,original_campaign_id,first_acquisition_channel,first_acquisition_at,status,merged_into_customer_id,created_by_user_id) VALUES (?,?,'0922334455','0922334455',?,?,?,?,?,?,?,?)")
        ->execute([$code, $name, $src['id'], $plat, $camp, $ch, $at, $status, $mergedInto, $adminId]);
    return (int) $db->lastInsertId();
};
$C1 = $cust('BDC-710001', 'Zerihun Tadesse P7', $S1, 'reception', '2025-03-01 00:00:00');
$C2 = $cust('BDC-710002', 'Meron Alemu P7', $S1, 'phone', '2025-03-15 12:00:00');
$C3 = $cust('BDC-710003', 'Liya Kebede P7', $S2, 'social_dm', '2025-03-31 23:59:59', $IG, $K1);
$C4 = $cust('BDC-710004', 'Outside After P7', $S2, 'phone', '2025-04-01 00:00:00');
$C5 = $cust('BDC-710005', 'Outside Before P7', $S1, 'phone', '2025-02-28 23:59:59');
$C6 = $cust('BDC-710006', 'Merged Dup P7', $S1, 'phone', '2025-03-10 10:00:00', null, null, 'merged', $C1);
$ia = function (int $c, string $ch, string $reason, int $first, string $at, array $x = []) use ($db, $callId) {
    $x += ['plat' => null, 'camp' => null, 'svc' => null, 'book' => 'not_applicable', 'use' => 'not_applicable', 'status' => 'complete'];
    $db->prepare('INSERT INTO interactions (customer_id,channel,interaction_reason,is_first_contact,interaction_at,social_platform_id,campaign_id,service_id,booking_outcome,service_usage_outcome,notes,record_status,recorded_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$c, $ch, $reason, $first, $at, $x['plat'], $x['camp'], $x['svc'], $x['book'], $x['use'], 'P7SECRETNOTE', $x['status'], $callId]);
    return (int) $db->lastInsertId();
};
$I1 = $ia($C1, 'reception', 'first_contact', 1, '2025-03-01 09:00:00', ['use' => 'used']);
$I2 = $ia($C1, 'reception', 'another_service', 0, '2025-03-05 10:00:00', ['use' => 'not_used']);
$I3 = $ia($C1, 'reception', 'another_service', 0, '2025-03-06 10:00:00', ['use' => 'unknown']);
$I4 = $ia($C2, 'phone', 'first_contact', 1, '2025-03-15 12:00:00', ['book' => 'requested', 'svc' => $SX['id']]);
$I5 = $ia($C2, 'phone', 'appointment_followup', 0, '2025-03-16 12:00:00', ['book' => 'not_booked', 'status' => 'needs_review']);
$I6 = $ia($C3, 'social_dm', 'first_contact', 1, '2025-03-31 23:59:59', ['plat' => $IG, 'camp' => $K1, 'book' => 'confirmed', 'svc' => $SX['id']]);
$I7 = $ia($C1, 'phone', 'campaign_response', 0, '2025-03-20 15:00:00', ['camp' => $K1, 'book' => 'confirmed', 'svc' => $SY['id']]);
$I8 = $ia($C5, 'social_dm', 'first_contact', 1, '2025-02-28 23:59:59', ['plat' => $IG, 'book' => 'confirmed']);
$I9 = $ia($C4, 'phone', 'first_contact', 1, '2025-04-01 00:00:00', ['book' => 'confirmed']);
$I10 = $ia($C3, 'social_dm', 'appointment_followup', 0, '2025-03-20 09:00:00', ['plat' => $IG, 'book' => 'requested']);
$ap = function (int $c, int $s, string $status, ?int $origin, string $created, ?string $at = null) use ($db, $callId) {
    $db->prepare('INSERT INTO appointments (customer_id,service_id,appointment_at,status,origin_interaction_id,created_by_user_id,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$c, $s, $at, $status, $origin, $callId, $created]);
    return (int) $db->lastInsertId();
};
$ev = fn(int $a, ?string $f, string $to, string $at) => $db->prepare('INSERT INTO appointment_events (appointment_id,from_status,to_status,user_id,event_at) VALUES (?,?,?,?,?)')->execute([$a, $f, $to, $callId, $at]);
$AP1 = $ap($C2, $SX['id'], 'requested', $I4, '2025-03-15 12:05:00');
$AP2 = $ap($C3, $SX['id'], 'completed', $I6, '2025-03-31 23:59:59');
$AP3 = $ap($C1, $SY['id'], 'cancelled', $I7, '2025-03-20 15:05:00');
$AP4 = $ap($C2, $SX['id'], 'no_show', null, '2025-03-12 08:00:00');          // no origin interaction: booked on created_at
$AP5 = $ap($C4, $SX['id'], 'confirmed', $I9, '2025-04-01 00:05:00');         // booked after the window
$AP6 = $ap($C5, $SX['id'], 'confirmed', $I8, '2025-02-28 23:59:59');        // booked in the previous period
$ev($AP1, null, 'requested', '2025-03-15 12:05:00');
$ev($AP2, null, 'confirmed', '2025-03-31 23:59:59'); $ev($AP2, 'confirmed', 'completed', '2025-03-31 23:59:59');
$ev($AP3, null, 'requested', '2025-03-20 15:05:00'); $ev($AP3, 'requested', 'confirmed', '2025-03-20 16:00:00'); $ev($AP3, 'confirmed', 'cancelled', '2025-03-21 09:00:00');
$ev($AP4, null, 'confirmed', '2025-03-12 08:00:00');
$lk = $db->prepare('INSERT INTO interaction_appointments (interaction_id,appointment_id,link_type) VALUES (?,?,?)');
foreach ([[$I4, $AP1, 'booked'], [$I10, $AP1, 'followup'], [$I5, $AP1, 'followup'], [$I6, $AP2, 'booked'], [$I7, $AP3, 'booked'], [$I10, $AP2, 'followup']] as $l) { $lk->execute($l); }

$child = function (array $args, array $env = []) {
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/p7_child.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..',
        $env + ['DB_HOST' => 'localhost', 'DB_USER' => 'babi_app', 'DB_PASS' => (string) getenv('PW'), 'DB_NAME' => 'babi_test', 'APP_ENV' => 'testing', 'APP_TIMEZONE' => 'UTC', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return json_decode(trim($o), true) ?? ['_error' => $o . $e];
};

section('B. Metric calculations (custom range 2025-03-01 .. 2025-03-31, expected values derived by hand)');
$m = $child(['metrics', '2025-03-01', '2025-03-31']);
t('metrics child ran without errors', !isset($m['_error']) && $m['errors'] === [], json_encode($m['_error'] ?? $m['errors'] ?? null));
t('new customers = 3 (start/end boundaries inclusive; outside, merged excluded)', $m['new'] === 3);
t('previous period (2025-01-29..2025-02-28) new customers = 1', $m['prev_new'] === 1);
t('interactions = 8 across 3 distinct customers (boundary rows 02-28 23:59:59 and 04-01 00:00:00 excluded)', $m['int']['total'] === 8 && $m['int']['customers'] === 3);
t('previous-period interactions = 1', $m['prev_int']['total'] === 1);
t('bookings by current status: requested 1, completed 1, cancelled 1, no-show 1, confirmed 0 (4 appointments, each once despite 6 links and 3 history rows)', $m['status'] === ['requested' => 1, 'confirmed' => 0, 'completed' => 1, 'cancelled' => 1, 'no_show' => 1]);
t('requested is not counted as confirmed; confirmed AP5/AP6 outside the window are not counted', $m['status']['confirmed'] === 0);
t('outcome rates: closed 3, completion/cancellation/no-show 33.3% each, open 1', $m['rates']['closed'] === 3 && $m['rates']['open'] === 1 && $m['rates']['completion'] == 33.3 && $m['rates']['cancellation'] == 33.3 && $m['rates']['no_show'] == 33.3);
t('previous-period bookings = 1 (AP6, dated by its origin interaction)', $m['prev_book'] === 1);
t('moved-into-status counts distinct appointments, creation events excluded: confirmed 1, completed 1, cancelled 1', $m['moved']['confirmed'] === 1 && $m['moved']['completed'] === 1 && $m['moved']['cancelled'] === 1 && $m['moved']['requested'] === 0 && $m['moved']['no_show'] === 0);
$bySrc = array_column($m['source'], null, 'name');
t('acquisition by original source: S1 = 2, S2 = 1 (ordered by count)', (int) $bySrc[$S1['name']]['n'] === 2 && (int) $bySrc[$S2['name']]['n'] === 1 && $m['source'][0]['name'] === $S1['name']);
t('source performance: S1 has 2 customers with an appointment, S2 has 1 with a completed one', (int) $bySrc[$S1['name']]['with_appt'] === 2 && (int) $bySrc[$S1['name']]['with_completed'] === 0 && (int) $bySrc[$S2['name']]['with_completed'] === 1);
t('acquisition by channel: 1 reception, 1 phone, 1 social DM', $m['acq_ch'] === ['reception' => 1, 'phone' => 1, 'social_dm' => 1]);
t('original social platform: Instagram = 1', count($m['platform']) === 1 && $m['platform'][0]['name'] === 'Instagram' && (int) $m['platform'][0]['n'] === 1);
t('the returning customer\'s later campaign response did not create a new customer (C1 counted once, as reception)', $m['acq_ch']['reception'] === 1 && $m['new'] === 3);
$ch = $m['channels'];
t('channel volumes: reception 3, phone 3, social DM 2', $ch['reception']['interactions'] === 3 && $ch['phone']['interactions'] === 3 && $ch['social_dm']['interactions'] === 2);
t('phone booking rate = (1 requested + 1 confirmed) / 3 decided = 66.7%', $ch['phone']['booking_rate'] === 66.7 && $ch['phone']['booking_decided'] === 3);
t('social DM booking rate = 2/2 = 100%', $ch['social_dm']['booking_rate'] == 100.0);
t('reception has NO booking rate; service-use rate = 1 used / (1 used + 1 not used) = 50%, unknown excluded', $ch['reception']['booking_rate'] === null && $ch['reception']['usage_rate'] == 50.0 && $ch['reception']['unknown'] === 1 && $ch['reception']['usage_decided'] === 2);
t('phone/DM have no service-use rate', $ch['phone']['usage_rate'] === null && $ch['social_dm']['usage_rate'] === null);
$ac = $m['appt_ch'];
t('appointments by origin channel: phone 2, social DM 1, none 1, reception 0 (one row per appointment)', array_sum($ac['phone']) === 2 && array_sum($ac['social_dm']) === 1 && array_sum($ac['none']) === 1 && array_sum($ac['reception']) === 0);
$camp = array_column($m['campaigns'], null, 'name');
t('campaign: 2 responses from 2 distinct customers (1 new, 1 returning)', (int) $camp['P7 Spring Promo']['responses'] === 2 && (int) $camp['P7 Spring Promo']['customers'] === 2 && (int) $camp['P7 Spring Promo']['first_contact'] === 1 && (int) $camp['P7 Spring Promo']['returning_resp'] === 1);
t('campaign: 1 customer acquired by original attribution, 2 appointments (1 completed, 1 cancelled), booking rate 100%', $camp['P7 Spring Promo']['acquired'] === 1 && $camp['P7 Spring Promo']['bookings'] === 2 && $camp['P7 Spring Promo']['appts']['completed'] === 1 && $camp['P7 Spring Promo']['appts']['cancelled'] === 1 && $camp['P7 Spring Promo']['booking_rate'] == 100.0);
t('inactive draft campaign without activity is hidden', !isset($camp['P7 Unused Draft']));
t('no cost / revenue / ROI figures are produced', !preg_match('/roi|revenue|cost/i', json_encode(array_keys($m['campaigns'][0] ?? []))));
$sv = array_column($m['services'], null, 'name');
t('services: X has 3 appointments (1 open, 1 completed, 1 no-show) and 2 interactions; Y has 1 cancelled and 1 interaction', $sv[$SX['name']]['bookings'] === 3 && $sv[$SX['name']]['appts']['completed'] === 1 && $sv[$SX['name']]['appts']['no_show'] === 1 && $sv[$SX['name']]['interest'] === 2 && $sv[$SY['name']]['bookings'] === 1 && $sv[$SY['name']]['interest'] === 1);
t('time series (31 days, daily) zero-filled and consistent with totals', count($m['series_new']['values']) === 31 && array_sum($m['series_new']['values']) === 3 && array_sum($m['series_book']['values']) === 4 && $m['series_new']['values'][0] === 1 && $m['series_new']['values'][30] === 1);

section('C. Empty dataset and zero denominators');
$e = $child(['metrics', '2024-06-01', '2024-06-07']);
t('empty period: all counts zero, no errors', $e['new'] === 0 && $e['int']['total'] === 0 && array_sum($e['status']) === 0 && $e['source'] === [] && array_sum($e['acq_ch']) === 0);
t('empty period: rates are null (n/a), never a division error', $e['rates']['completion'] === null && $e['rates']['cancellation'] === null && $e['rates']['no_show'] === null && $e['channels']['phone']['booking_rate'] === null && $e['channels']['reception']['usage_rate'] === null);
t('empty period: series are all zeros of the right length', count($e['series_new']['values']) === 7 && array_sum($e['series_new']['values']) === 0);
$only = $child(['metrics', '2025-03-12', '2025-03-12']); // only AP4 (no-show) booked
t('single day: closed=1 -> no-show 100%, completion 0%', $only['rates']['no_show'] == 100.0 && $only['rates']['completion'] == 0.0 && $only['rates']['completion'] !== null);

section('D. Timezone: same stored local values give the same answers; presets follow APP_TIMEZONE');
foreach (['Africa/Addis_Ababa', 'America/New_York', 'Pacific/Auckland'] as $tz) {
    $x = $child(['metrics', '2025-03-01', '2025-03-31'], ['APP_TIMEZONE' => $tz]);
    t("$tz: identical metrics for the fixture window", ($x['new'] ?? -1) === 3 && ($x['int']['total'] ?? -1) === 8 && ($x['status'] ?? []) === $m['status'] && ($x['channels']['phone']['booking_rate'] ?? 0) === 66.7);
}
$inst = '2026-03-01 21:30:00'; // 21:30 UTC = 00:30 on 2 March in Addis Ababa, still 1 March in UTC and New York
$ra = $child(['range', 'today', $inst], ['APP_TIMEZONE' => 'Africa/Addis_Ababa']);
$ru = $child(['range', 'today', $inst], ['APP_TIMEZONE' => 'UTC']);
$rn = $child(['range', 'today', $inst], ['APP_TIMEZONE' => 'America/New_York']);
t('"today" at 21:30 UTC is 2 March in Addis Ababa, 1 March in UTC and New York', $ra['from'] === '2026-03-02' && $ru['from'] === '2026-03-01' && $rn['from'] === '2026-03-01');
t('today: start 00:00:00, exclusive end = next midnight', $ra['start'] === '2026-03-02 00:00:00' && $ra['end'] === '2026-03-03 00:00:00' && $ra['days'] === 1);
$l7 = $child(['range', 'last7', $inst], ['APP_TIMEZONE' => 'Africa/Addis_Ababa']);
t('last 7 days = 7 inclusive days ending today; previous period is the 7 days before', $l7['from'] === '2026-02-24' && $l7['to'] === '2026-03-02' && $l7['days'] === 7 && $l7['pfrom'] === '2026-02-17' && $l7['pto'] === '2026-02-23');
$l30 = $child(['range', 'last30', $inst], ['APP_TIMEZONE' => 'UTC']);
t('last 30 days (default preset) = 30 inclusive days, daily buckets', $l30['days'] === 30 && $l30['from'] === '2026-01-31' && $l30['gran'] === 'day' && $l30['buckets'] === 30);
$tm = $child(['range', 'this_month', $inst], ['APP_TIMEZONE' => 'Africa/Addis_Ababa']);
t('this month starts on the 1st of the local month (March, not February)', $tm['from'] === '2026-03-01' && $tm['to'] === '2026-03-02');
$tmU = $child(['range', 'this_month', '2026-03-31 22:00:00'], ['APP_TIMEZONE' => 'Africa/Addis_Ababa']);
t('this month at 22:00 UTC on 31 March is already 1 April in Addis Ababa', $tmU['from'] === '2026-04-01' && $tmU['to'] === '2026-04-01');
$cu = $child(['range', 'custom', $inst, '2026-01-01', '2026-02-28'], ['APP_TIMEZONE' => 'UTC']);
t('custom range accepted: 59 days, previous period equal length', $cu['preset'] === 'custom' && $cu['days'] === 59 && $cu['errors'] === [] && $cu['pto'] === '2025-12-31');
$wk = $child(['range', 'custom', $inst, '2025-10-01', '2026-02-28'], ['APP_TIMEZONE' => 'UTC']);
t('151-day range uses weekly buckets starting on Mondays', $wk['gran'] === 'week' && $wk['first_bucket'] === '2025-09-29');
$mo = $child(['range', 'custom', $inst, '2024-01-01', '2026-02-28'], ['APP_TIMEZONE' => 'UTC']);
t('long range uses monthly buckets', $mo['gran'] === 'month' && $mo['buckets'] === 26);
foreach ([['bad', 'unknown preset', []], ['custom', 'reversed dates', ['2026-02-10', '2026-02-01']], ['custom', 'invalid date', ['2026-02-30', '2026-03-01']], ['custom', 'future end date', ['2026-02-01', '2026-03-20']], ['custom', 'range over 1095 days', ['2020-01-01', '2026-03-01']], ['custom', 'missing dates', ['', '']]] as [$pre, $why, $ft]) {
    $x = $child(array_merge(['range', $pre, $inst], $ft), ['APP_TIMEZONE' => 'UTC']);
    t("invalid input ($why) falls back to last 30 days with a message", $x['preset'] === 'last30' && count($x['errors']) === 1 && $x['days'] === 30);
}
$inv = $child(['metrics', '2025-03-01', '2025-03-31'], ['APP_TIMEZONE' => 'Mars/Olympus']);
t('invalid APP_TIMEZONE still stops the analytics code clearly (no silent fallback)', isset($inv['_error']) && str_contains($inv['_error'], 'Invalid APP_TIMEZONE'));

section('E. HTTP: authorization (server-side), privacy and validation');
$pages = ['/analytics', '/analytics/acquisition', '/analytics/channels', '/analytics/campaigns', '/analytics/appointments', '/marketing'];
$q = '?range=custom&from=2025-03-01&to=2025-03-31';
foreach ($pages as $p) { $r = $ANON->req('GET', $p); t("anonymous $p -> login", $r['status'] === 302 && $r['loc'] === '/login'); }
foreach (['REC' => $REC, 'CALL' => $CALL, 'SOC' => $SOC] as $n => $c) { foreach (array_slice($pages, 0, 5) as $p) { t("entry role $n GET $p -> 403", $c->req('GET', $p . $q)['status'] === 403); } }
foreach ($pages as $p) { t("marketing GET $p -> 200", $MKT->req('GET', $p . $q)['status'] === 200); t("admin GET $p -> 200", $ADM->req('GET', $p . $q)['status'] === 200); }
t('analytics accepts GET only (POST -> 404/405/403)', in_array($ADM->post('/analytics', '/analytics', [])['status'], [403, 404, 405], true));
$auditBefore = (int) $db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$secrets = ['Zerihun', 'Meron', 'Liya', 'Outside', 'Merged Dup', 'BDC-71', '0922334455', 'P7SECRETNOTE'];
$leak = [];
foreach ([$MKT, $ADM] as $who => $c) { foreach ($pages as $p) { $b = $c->req('GET', $p . $q)['body']; foreach ($secrets as $s) { if (str_contains($b, $s)) { $leak[] = "$who:$p:$s"; } } } }
t('no customer name, code, phone or interaction note appears on any analytics page (marketing and admin)', $leak === [], implode(', ', $leak));
$ov = $MKT->req('GET', '/analytics' . $q)['body']; $ovA = $ADM->req('GET', '/analytics' . $q)['body'];
t('data-quality panel is shown to administrators only', !str_contains($ov, 'Data quality') && str_contains($ovA, 'Data quality') && str_contains($ovA, '1 interaction(s) in the period are flagged'));
t('overview shows the explicit period and the status-cohort explanation', str_contains($ov, '1 Mar 2025 to 31 Mar 2025') && str_contains($ov, 'current status'));
t('overview figures rendered: 3 new customers, 8 interactions, 4 bookings', (bool) preg_match('/New customers acquired<\/div><div class="kpi-value">3</', $ov) && (bool) preg_match('/Interactions recorded<\/div><div class="kpi-value">8</', $ov) && (bool) preg_match('/Appointment bookings<\/div><div class="kpi-value">4</', $ov));
t('comparison with previous period is shown only where defined', str_contains($ov, '+200.0% vs previous period (1)') && str_contains($ov, '+300.0% vs previous period (1)'));
t('campaigns page states that ROI/cost cannot be calculated', str_contains($MKT->req('GET', '/analytics/campaigns' . $q)['body'], 'cost per acquisition and revenue cannot be calculated'));
t('channels page shows no reception booking rate', str_contains($MKT->req('GET', '/analytics/channels' . $q)['body'], 'not applicable (reception does not book)'));
t('empty period renders empty states, not errors', (function () use ($MKT) { foreach (['/analytics', '/analytics/acquisition', '/analytics/channels', '/analytics/campaigns', '/analytics/appointments'] as $p) { $r = $MKT->req('GET', $p . '?range=custom&from=2024-06-01&to=2024-06-07'); if ($r['status'] !== 200 || !str_contains($r['body'], 'No data in this period') && !str_contains($r['body'], 'No ')) return false; } return true; })());
foreach (['range=bogus', 'range=custom&from=x&to=y', 'range=custom&from=2026-02-10&to=2026-02-01', "range=custom&from=%27%20OR%201=1--&to=2026-01-01", 'range[]=a', 'range=custom&from[]=1&to[]=2'] as $qs) {
    $r = $MKT->req('GET', "/analytics?$qs");
    t("bad filter ($qs) -> 200 with warning, no SQL/stack output", $r['status'] === 200 && str_contains($r['body'], 'alert-warning') && !preg_match('/SQLSTATE|Stack trace|PDOException|Warning:|Fatal error/i', $r['body']));
}
t('XSS in query parameters is not reflected unescaped', !str_contains($MKT->req('GET', '/analytics?range=custom&from=%3Cscript%3Ealert(1)%3C/script%3E&to=x')['body'], '<script>alert(1)'));
t('every analytics page: CSP, no inline script/style/handlers, loads local Chart.js, canvases carry data', (function () use ($ADM, $pages, $q) { foreach ($pages as $p) { $r = $ADM->req('GET', $p . $q); if (!str_contains($r['headers'], "Content-Security-Policy: default-src 'self'") || !str_contains($r['headers'], 'no-store') || preg_match('/<script(?![^>]*\bsrc=)|<[^>]*\s(on[a-z]+|style)=/i', preg_replace('/\sstyle="(background-color: #173b77 !important; color: #ffffff !important;)?"/', '', $r['body'])) || !str_contains($r['body'], '/assets/vendor/chartjs/chart.umd.min.js') || !str_contains($r['body'], 'data-chart=')) return false; } return true; })());
t('no external hosts referenced (no CDN)', (function () use ($ADM, $pages, $q) { foreach ($pages as $p) { if (preg_match('#(src|href)="https?://#i', $ADM->req('GET', $p . $q)['body'])) return false; } return true; })());
$asset = $ANON->req('GET', '/assets/vendor/chartjs/chart.umd.min.js'); $js = $ANON->req('GET', '/assets/js/analytics.js');
t('Chart.js and analytics.js are served locally', $asset['status'] === 200 && str_contains($asset['body'], 'Chart.js v4') && $js['status'] === 200);
t('navigation: Analytics link for marketing and admin, not for entry roles', str_contains($MKT->req('GET', '/marketing')['body'], 'href="/analytics"') && str_contains($ADM->req('GET', '/admin')['body'], 'href="/analytics"') && !str_contains($REC->req('GET', '/reception')['body'], 'href="/analytics"'));
t('viewing analytics wrote nothing (audit log unchanged)', (int) $db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditBefore);

section('F. Cleanup of fixtures');
$db->exec("DELETE FROM interaction_appointments WHERE appointment_id IN ($AP1,$AP2,$AP3,$AP4,$AP5,$AP6)");
$db->exec("DELETE FROM appointment_events WHERE appointment_id IN ($AP1,$AP2,$AP3,$AP4,$AP5,$AP6)");
$db->exec("DELETE FROM appointments WHERE id IN ($AP1,$AP2,$AP3,$AP4,$AP5,$AP6)");
$db->exec("DELETE FROM interactions WHERE customer_id IN ($C1,$C2,$C3,$C4,$C5,$C6)");
$db->exec("UPDATE customers SET merged_into_customer_id = NULL WHERE id = $C6");
$db->exec("DELETE FROM customers WHERE id IN ($C1,$C2,$C3,$C4,$C5,$C6)");
$db->exec("DELETE FROM campaigns WHERE id IN ($K1,$K2)");
t('fixtures removed', $inWin('customers', 'first_acquisition_at') + $inWin('interactions', 'interaction_at') + $inWin('appointments', 'created_at') === 0);

finish('Phase 7 suite');
