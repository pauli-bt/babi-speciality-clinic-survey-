<?php
declare(strict_types=1);
// Phase 8 suite: reports and exports. Fixtures sit in March 2025 (queried with a custom range) so they cannot mix with
// the NOW()-based data of other suites; expected numbers are derived by hand in the comments.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $P8 = 'Maple-Harbor-8416';
$role = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$hash = password_hash($P8, PASSWORD_BCRYPT, ['cost' => 12]);
foreach ([['p8_rec', 'receptionist'], ['p8_call', 'call_operator'], ['p8_soc', 'social_tracker'], ['p8_mkt', 'marketing']] as [$u, $r]) {
    $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password, password_changed_at) VALUES (?,?,?,?,0,NOW())')->execute([ucfirst($r) . ' Eight', $u, $hash, $role[$r]]);
}
$login = function (string $u, string $p) { $c = new Client(); $c->login($u, $p); return $c; };
$REC = $login('p8_rec', $P8); $CALL = $login('p8_call', $P8); $SOC = $login('p8_soc', $P8); $MKT = $login('p8_mkt', $P8); $ADM = $login('boss', $ADMIN_PW); $ANON = new Client();
$uidOf = fn(string $u) => (int) $db->query("SELECT id FROM users WHERE username='$u'")->fetchColumn();
$adminId = $uidOf('boss'); $callId = $uidOf('p8_call');
$S1 = $db->query('SELECT id, name FROM acquisition_sources ORDER BY id LIMIT 1')->fetch();
$bodyOf = fn(array $r) => $r['body'];

$child = function (array $args, array $env = []) {
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/p8_child.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..',
        $env + ['DB_HOST' => 'localhost', 'DB_USER' => 'babi_app', 'DB_PASS' => (string) getenv('PW'), 'DB_NAME' => 'babi_test', 'APP_ENV' => 'testing', 'APP_TIMEZONE' => 'UTC', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $o . $e;
};
$parseCsv = function (string $body): array {
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $h = fopen('php://memory', 'w+'); fwrite($h, $body); rewind($h); $rows = [];
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) { $rows[] = $r; }
    fclose($h);
    return $rows;
};
$rowOf = function (array $rows, string $first) { foreach ($rows as $r) { if (($r[0] ?? null) === $first) return $r; } return null; };

section('A. CSV encoding and formula-injection protection (unit)');
$lines = explode("\n", trim($child(['csv'])));
$got = json_decode($lines[0], true);
$exp = ['plain', '"a,b"', '"say ""hi"""', "\"line1\nline2\"", "'=1+1", "'+SUM(A1)", "'-2+3", "'@cmd", "'\t=x", "\"'\r=x\"", "' =x", "'=already", '', 'አማርኛ',
    "\"'=HYPERLINK(\"\"http://e.example\"\",\"\"x\"\"),with \"\"q\"\"\"", '"trail "', "\"a\r\nb\"", '0', '-5', '-20.5', '100', '66.7', '0', ''];
t('cell encoding matches the expected output for all 24 cases', is_array($got) && $got === $exp, json_encode($got));
t('negative numbers stay numeric (no apostrophe); formula text gets one', $got[18] === '-5' && $got[19] === '-20.5' && $got[6] === "'-2+3");
t('row() joins with commas, ends with CRLF, null is an empty cell', json_decode($lines[1], true) === "a,\"b,c\",3,\r\n");
t('quotes, commas and line breaks round-trip through a CSV parser', (function () use ($parseCsv) { $s = "\xEF\xBB\xBFx,y\r\n\"a,b\",\"q\"\"z\"\"\"\r\n\"l1\nl2\",\"'=1\"\r\n"; $r = $parseCsv($s); return $r[1] === ['a,b', 'q"z"'] && $r[2] === ["l1\nl2", "'=1"]; })());

// ---- fixtures ---------------------------------------------------------------------------------------------------
$inWin = fn(string $t, string $c) => (int) $db->query("SELECT COUNT(*) FROM $t WHERE $c >= '2025-01-29' AND $c < '2025-04-02'")->fetchColumn();
section('B. Fixture isolation');
t('March 2025 window is empty before fixtures', $inWin('customers', 'first_acquisition_at') + $inWin('interactions', 'interaction_at') + $inWin('appointments', 'created_at') === 0);
$EVIL_CAMPAIGN = '=HYPERLINK("http://evil.example","x"),with "quotes"';
$EVIL_SERVICE = '@SUM(1+1)';
$db->prepare("INSERT INTO campaigns (name, platform_id, campaign_code, start_date, end_date, status) VALUES (?,NULL,'P8-C','2025-03-01','2025-03-31','active')")->execute([$EVIL_CAMPAIGN]); $K = (int) $db->lastInsertId();
$db->prepare("INSERT INTO services (name, description, is_active) VALUES (?, NULL, 1)")->execute([$EVIL_SERVICE]); $Z = (int) $db->lastInsertId();
$cust = function (string $code, string $name, string $ch, string $at, ?int $camp = null) use ($db, $adminId, $S1) {
    $db->prepare("INSERT INTO customers (customer_code,name,phone,phone_normalized,original_source_id,original_campaign_id,first_acquisition_channel,first_acquisition_at,created_by_user_id) VALUES (?,?,'0933445566','0933445566',?,?,?,?,?)")->execute([$code, $name, $S1['id'], $camp, $ch, $at, $adminId]);
    return (int) $db->lastInsertId();
};
$C1 = $cust('BDC-720001', 'Hiwot Secret P8', 'reception', '2025-03-02 08:00:00');
$C2 = $cust('BDC-720002', 'Abel Secret P8', 'phone', '2025-03-10 11:00:00', $K);
$C3 = $cust('BDC-720003', 'Outside Secret P8', 'phone', '2025-04-01 00:00:00');
$ia = function (int $c, string $ch, string $reason, int $first, string $at, array $x = []) use ($db, $callId, $Z) {
    $x += ['camp' => null, 'book' => 'not_applicable', 'use' => 'not_applicable'];
    $db->prepare('INSERT INTO interactions (customer_id,channel,interaction_reason,is_first_contact,interaction_at,campaign_id,service_id,booking_outcome,service_usage_outcome,notes,recorded_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$c, $ch, $reason, $first, $at, $x['camp'], $Z, $x['book'], $x['use'], 'P8SECRETNOTE', $callId]);
    return (int) $db->lastInsertId();
};
$I1 = $ia($C1, 'reception', 'first_contact', 1, '2025-03-02 08:00:00', ['use' => 'used']);
$I2 = $ia($C2, 'phone', 'first_contact', 1, '2025-03-10 11:00:00', ['camp' => $K, 'book' => 'confirmed']);
$I3 = $ia($C1, 'phone', 'campaign_response', 0, '2025-03-12 09:00:00', ['camp' => $K, 'book' => 'requested']);
$I5 = $ia($C2, 'phone', 'appointment_followup', 0, '2025-03-31 23:59:59', ['book' => 'not_booked']);
$I4 = $ia($C3, 'phone', 'first_contact', 1, '2025-04-01 00:00:00', ['book' => 'confirmed']);
$ap = function (int $c, string $status, int $origin, string $created) use ($db, $callId, $Z) {
    $db->prepare('INSERT INTO appointments (customer_id,service_id,appointment_at,status,origin_interaction_id,created_by_user_id,created_at) VALUES (?,?,NULL,?,?,?,?)')->execute([$c, $Z, $status, $origin, $callId, $created]);
    return (int) $db->lastInsertId();
};
$AP1 = $ap($C2, 'completed', $I2, '2025-03-10 11:05:00');
$AP2 = $ap($C1, 'requested', $I3, '2025-03-12 09:05:00');
$ev = fn(int $a, ?string $f, string $to, string $at) => $db->prepare('INSERT INTO appointment_events (appointment_id,from_status,to_status,user_id,event_at) VALUES (?,?,?,?,?)')->execute([$a, $f, $to, $callId, $at]);
$ev($AP1, null, 'confirmed', '2025-03-10 11:05:00'); $ev($AP1, 'confirmed', 'completed', '2025-03-15 10:00:00'); $ev($AP2, null, 'requested', '2025-03-12 09:05:00');
$lk = $db->prepare('INSERT INTO interaction_appointments (interaction_id,appointment_id,link_type) VALUES (?,?,?)');
foreach ([[$I2, $AP1, 'booked'], [$I5, $AP1, 'followup'], [$I3, $AP2, 'booked'], [$I5, $AP2, 'followup']] as $l) { $lk->execute($l); }

$Q = 'range=custom&from=2025-03-01&to=2025-03-31';
$types = ['summary', 'acquisition', 'channels', 'campaigns', 'appointments', 'services', 'status_movements'];
$snapshot = fn() => md5(json_encode([$db->query("SELECT id,original_source_id,first_acquisition_at,status FROM customers WHERE id IN ($C1,$C2,$C3) ORDER BY id")->fetchAll(), $db->query("SELECT id,status,created_at FROM appointments WHERE id IN ($AP1,$AP2) ORDER BY id")->fetchAll(), $db->query("SELECT id,to_status,event_at FROM appointment_events WHERE appointment_id IN ($AP1,$AP2) ORDER BY id")->fetchAll()]));
$snapBefore = $snapshot();
$auditN = fn(string $like) => (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '$like'")->fetchColumn();

section('C. Authorization (server-side) and routing');
foreach (['/reports'] as $p) { $r = $ANON->req('GET', $p); t("anonymous $p -> login", $r['status'] === 302 && $r['loc'] === '/login'); }
foreach (['/reports/summary/csv', '/reports/campaigns/print'] as $p) { $r = $ANON->req('GET', "$p?$Q"); t("anonymous $p -> login, no data", $r['status'] === 302 && $r['loc'] === '/login' && !str_contains($r['body'], 'Report')); }
$paths = ['/reports']; foreach ($types as $ty) { array_push($paths, "/reports/$ty", "/reports/$ty/csv", "/reports/$ty/print"); }
$deny = [];
foreach (['REC' => $REC, 'CALL' => $CALL, 'SOC' => $SOC] as $n => $c) { foreach ($paths as $p) { if ($c->req('GET', "$p?$Q")['status'] !== 403) { $deny[] = "$n $p"; } } }
t('entry roles get 403 on the index and on every view, CSV and print route (24 routes x 3 roles)', $deny === [], implode(', ', $deny));
$okc = [];
foreach (['MKT' => $MKT, 'ADM' => $ADM] as $n => $c) { foreach ($paths as $p) { if ($c->req('GET', "$p?$Q")['status'] !== 200) { $okc[] = "$n $p"; } } }
t('marketing and administrator get 200 on all report routes', $okc === [], implode(', ', $okc));
foreach (['/reports/customers', '/reports/customers/csv', '/reports/summary/xlsx', '/reports/summary/pdf', '/reports/../etc/passwd', '/reports/SUMMARY', '/reports/summary/csv/extra'] as $p) { t("unknown report/format $p -> 404", $ADM->req('GET', "$p?$Q")['status'] === 404); }
t('POST to a report URL is refused (405) even with a valid session', $ADM->req('POST', '/reports/summary/csv', [])['status'] === 405);
t('no route exists for identifiable-record exports', $ADM->req('GET', '/reports/customer_list/csv?' . $Q)['status'] === 404 && $ADM->req('GET', '/reports/interactions/csv?' . $Q)['status'] === 404);
t('navigation shows Reports to marketing and admin only', str_contains($MKT->req('GET', '/marketing')['body'], 'href="/reports"') && str_contains($ADM->req('GET', '/admin')['body'], 'href="/reports"') && !str_contains($REC->req('GET', '/reception')['body'], 'href="/reports"'));

section('D. Download headers');
$auditCsv0 = $auditN('export.csv');
$r = $MKT->req('GET', "/reports/summary/csv?$Q");
t('CSV: 200, text/csv; charset=utf-8', $r['status'] === 200 && stripos($r['headers'], 'Content-Type: text/csv; charset=utf-8') !== false);
t('CSV: attachment with a safe, deterministic filename', str_contains($r['headers'], 'Content-Disposition: attachment; filename="babi-summary_2025-03-01_to_2025-03-31.csv"'));
t('CSV: no-store and nosniff', stripos($r['headers'], 'no-store') !== false && stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false);
t('CSV: Content-Length matches the body; body starts with a UTF-8 BOM and CRLF line ends', preg_match('/Content-Length: (\d+)/i', $r['headers'], $mm) === 1 && (int) $mm[1] === strlen($r['body']) && str_starts_with($r['body'], "\xEF\xBB\xBF") && str_contains($r['body'], "\r\n"));
$sum = $parseCsv($r['body']);
t('CSV metadata: report title, inclusive period label, timezone, generated stamp', ($rowOf($sum, 'Report')[1] ?? '') === 'Summary for the period' && str_contains($rowOf($sum, 'Reporting period')[1] ?? '', '1 Mar 2025 to 31 Mar 2025 (31 days, inclusive') && ($rowOf($sum, 'Timezone')[1] ?? '') === 'UTC' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $rowOf($sum, 'Generated')[1] ?? '') === 1);

section('E. Report contents (Phase 7 definitions; expected values derived by hand)');
$kv = fn(array $rows, string $k) => $rowOf($rows, $k);
$x = $kv($sum, 'New customers acquired'); t('summary: new customers = 2 (boundary customer on 04-01 excluded); previous = 0 so change is empty', $x[1] === '2' && $x[2] === '0' && $x[3] === '');
$x = $kv($sum, 'Interactions recorded'); t('summary: interactions = 4 (03-31 23:59:59 included, 04-01 00:00:00 excluded)', $x[1] === '4');
$x = $kv($sum, 'Distinct customers with an interaction'); t('summary: 2 distinct customers', $x[1] === '2');
$x = $kv($sum, 'Appointment bookings'); t('summary: 2 bookings (each once despite 4 links and 3 history rows)', $x[1] === '2');
t('summary: booked-in-period status split completed 1, requested 1, confirmed 0 (requested is not confirmed)', $kv($sum, 'Booked in period, currently Completed')[1] === '1' && $kv($sum, 'Booked in period, currently Requested')[1] === '1' && $kv($sum, 'Booked in period, currently Confirmed')[1] === '0' && $kv($sum, 'Booked in period, currently Cancelled')[1] === '0');
t('summary: closed 1, open 1, completion 100, cancellation 0, no-show 0', $kv($sum, 'Closed (completed + cancelled + no-show)')[1] === '1' && $kv($sum, 'Open (requested + confirmed)')[1] === '1' && $kv($sum, 'Completion rate (%)')[1] === '100' && $kv($sum, 'Cancellation rate (%)')[1] === '0');
t('summary: status rows are not compared with the previous period', $kv($sum, 'Booked in period, currently Completed')[2] === '' && $kv($sum, 'Booked in period, currently Completed')[3] === '');
$acq = $parseCsv($ADM->req('GET', "/reports/acquisition/csv?$Q")['body']);
$x = $rowOf($acq, $S1['name']);
t('acquisition: original source row = 2 new customers, 100% share, 2 with an appointment, 1 with a completed one', $x[2] === '2' && $x[3] === '100' && $x[4] === '2' && $x[5] === '1');
t('acquisition: channel rows reception 1, phone 1, social DM 0; series has 31 daily rows summing to 2', $rowOf($acq, 'Reception')[1] === '1' && $rowOf($acq, 'Telephone')[1] === '1' && $rowOf($acq, 'Social media DM')[1] === '0' && count(array_filter($acq, fn($r) => preg_match('/^2025-03-\d\d$/', $r[0] ?? '') === 1)) === 31 && array_sum(array_map(fn($r) => preg_match('/^2025-03-\d\d$/', $r[0] ?? '') ? (int) $r[1] : 0, $acq)) === 2);
$ch = $parseCsv($MKT->req('GET', "/reports/channels/csv?$Q")['body']);
$rec = $rowOf($ch, 'Reception'); $ph = $rowOf($ch, 'Telephone');
t('channels: reception has no booking columns and a service-use rate of 100 (1 used / 1 decided)', $rec[1] === '1' && $rec[3] === '' && $rec[7] === '' && $rec[8] === '1' && $rec[12] === '100');
t('channels: telephone 3 interactions, booking rate 66.7 (2 of 3), no service-use rate', $ph[1] === '3' && $ph[3] === '1' && $ph[4] === '1' && $ph[5] === '1' && $ph[6] === '3' && $ph[7] === '66.7' && $ph[12] === '');
t('channels: appointments by originating channel: telephone 2 (1 open, 1 completed), reception absent', ($a = $rowOf($ch, 'Telephone')) !== null && count(array_filter($ch, fn($r) => ($r[0] ?? '') === 'Telephone' && ($r[1] ?? '') === '2' && ($r[3] ?? '') === '1' && ($r[2] ?? '') === '1')) === 1);
$cp = $parseCsv($MKT->req('GET', "/reports/campaigns/csv?$Q")['body']);
$x = null; foreach ($cp as $r) { if (str_contains($r[0] ?? '', 'HYPERLINK')) { $x = $r; } }
t('campaigns: malicious campaign name is neutralised with a leading quote and survives quoting intact', $x !== null && $x[0] === "'" . $EVIL_CAMPAIGN);
t('campaigns: 2 responses, 2 distinct customers, 1 from new / 1 from returning, 1 acquired by original attribution', $x[3] === '2' && $x[4] === '2' && $x[5] === '1' && $x[6] === '1' && $x[7] === '1');
t('campaigns: booking rate 100 (2 of 2), 2 appointments (1 open, 1 completed)', $x[8] === '2' && $x[9] === '2' && $x[10] === '100' && $x[11] === '2' && $x[12] === '1' && $x[13] === '1');
t('campaigns: states that cost/revenue/ROI cannot be reported and has no ROI column', str_contains(implode(' ', array_map(fn($r) => implode(' ', $r), $cp)), 'No cost or revenue data is stored') && !preg_grep('/roi|revenue|cost per/i', $cp[array_key_last(array_filter($cp, fn($r) => ($r[0] ?? '') === 'Campaign'))] ?? []));
$ap = $parseCsv($MKT->req('GET', "/reports/appointments/csv?$Q")['body']);
t('appointments: statuses completed 1 (50%), requested 1 (50%), confirmed 0; outcome rates', $rowOf($ap, 'Completed')[1] === '1' && $rowOf($ap, 'Completed')[2] === '50' && $rowOf($ap, 'Requested')[1] === '1' && $rowOf($ap, 'Confirmed')[1] === '0' && $rowOf($ap, 'Completion rate (%)')[1] === '100');
$sv = $parseCsv($MKT->req('GET', "/reports/services/csv?$Q")['body']);
$x = null; foreach ($sv as $r) { if (str_contains($r[0] ?? '', 'SUM(1+1)')) { $x = $r; } }
t('services: malicious service name neutralised; 4 interactions name it, 2 appointments (1 open, 1 completed)', $x !== null && $x[0] === "'" . $EVIL_SERVICE && $x[2] === '4' && $x[3] === '2' && $x[4] === '1' && $x[5] === '1');
$sm = $parseCsv($MKT->req('GET', "/reports/status_movements/csv?$Q")['body']);
$firstTable = array_slice($sm, 0, 0);
t('status movements: first table (events) completed 1, confirmed 0, requested 0 (creation events excluded)', (function () use ($sm) { $t1 = false; foreach ($sm as $i => $r) { if (($r[0] ?? '') === 'Appointments that moved into a status during the period') { $t1 = $i; } } if ($t1 === false) return false; $v = []; for ($j = $t1 + 2; $j < $t1 + 7; $j++) { $v[$sm[$j][0]] = $sm[$j][1]; } return $v === ['Requested' => '0', 'Confirmed' => '0', 'Completed' => '1', 'Cancelled' => '0', 'No-show' => '0']; })());
t('status movements: comparison table shows the booking cohort (completed 1, requested 1) separately', (function () use ($sm) { foreach ($sm as $i => $r) { if (str_starts_with($r[0] ?? '', 'For comparison')) { return $sm[$i + 4][1] === '1' && $sm[$i + 2][1] === '1'; } } return false; })());
t('empty period still downloads: header, tables and zero rows, rates empty', (function () use ($ADM, $parseCsv) { $r = $ADM->req('GET', '/reports/summary/csv?range=custom&from=2024-06-01&to=2024-06-07'); $c = $parseCsv($r['body']); $x = null; foreach ($c as $row) { if (($row[0] ?? '') === 'Completion rate (%)') $x = $row; } return $r['status'] === 200 && $x !== null && $x[1] === ''; })());

section('F. Privacy: no restricted data in any report');
$secrets = ['Hiwot', 'Abel', 'Outside Secret', 'BDC-72', '0933445566', 'P8SECRETNOTE', 'Secret P8'];
$leak = [];
foreach (['MKT' => $MKT, 'ADM' => $ADM] as $n => $c) { foreach ($types as $ty) { foreach (['', '/csv', '/print'] as $f) { $b = $c->req('GET', "/reports/$ty$f?$Q")['body']; foreach ($secrets as $s) { if (str_contains($b, $s)) { $leak[] = "$n:$ty$f:$s"; } } } } }
t('no customer name, code, phone number or note appears in any view, CSV or print output (21 reports x 2 roles)', $leak === [], implode(', ', $leak));

section('G. Print view');
$pr = $MKT->req('GET', "/reports/campaigns/print?$Q"); $pb = $pr['body'];
t('print view: title, period, timezone, generated date, notes and logo', str_contains($pb, 'Campaign responses and performance') && str_contains($pb, '1 Mar 2025 to 31 Mar 2025') && str_contains($pb, 'Timezone') && str_contains($pb, 'Generated') && str_contains($pb, 'No cost or revenue data is stored') && str_contains($pb, '/assets/images/babi-logo.jpg'));
t('print view: no navigation bar, has a print button handled by an external script, no PDF claim of server generation', !str_contains($pb, 'navbar') && str_contains($pb, 'data-print') && str_contains($pb, '/assets/js/print.js') && !preg_match('/<(embed|iframe|object)/i', $pb));
t('print view: CSP, no inline script/style/handlers', str_contains($pr['headers'], "Content-Security-Policy: default-src 'self'") && !preg_match('/<script(?![^>]*\bsrc=)|<[^>]*\s(on[a-z]+|style)=/i', $pb));
t('print and on-screen views escape the campaign name', str_contains($ADM->req('GET', "/reports/campaigns?$Q")['body'], '=HYPERLINK(&quot;http://evil.example&quot;,&quot;x&quot;),with &quot;quotes&quot;'));
$jsr = $ANON->req('GET', '/assets/js/print.js'); t('print.js is served', $jsr['status'] === 200);

section('H. Date-range validation, boundaries and timezone');
foreach (['range=bogus', 'range=custom&from=2025-03-10&to=2025-03-01', 'range=custom&from=2025-02-30&to=2025-03-01', 'range=custom&from=2025-03-01&to=2999-01-01', 'range=custom&from=&to=', 'range=custom&from=2020-01-01&to=2025-03-01', "range=custom&from=2025-03-01\"%0d%0aX-Evil:1&to=2025-03-02", 'range[]=custom', 'range=custom&from[]=1&to[]=2'] as $qs) {
    foreach (['csv', 'print'] as $f) {
        $r = $ADM->req('GET', "/reports/summary/$f?$qs");
        t("invalid ($qs) on $f -> 422, no file, no header injection", $r['status'] === 422 && stripos($r['headers'], 'text/csv') === false && stripos($r['headers'], 'Content-Disposition') === false && stripos($r['headers'], 'X-Evil') === false && !preg_match('/SQLSTATE|Stack trace|PDOException|Fatal error/i', $r['body']));
    }
}
$r = $ADM->req('GET', '/reports/summary?range=bogus'); t('on-screen view with a bad range falls back to last 30 days with a warning (200)', $r['status'] === 200 && str_contains($r['body'], 'alert-warning'));
t('presets work for downloads (today, last7, last30, this_month)', (function () use ($ADM) { foreach (['today', 'last7', 'last30', 'this_month'] as $p) { $r = $ADM->req('GET', "/reports/summary/csv?range=$p"); if ($r['status'] !== 200 || !preg_match('/filename="babi-summary_\d{4}-\d\d-\d\d_to_\d{4}-\d\d-\d\d\.csv"/', $r['headers'])) return false; } return true; })());
$base = json_decode($child(['build', 'summary', '2025-03-01', '2025-03-31']), true);
foreach (['Africa/Addis_Ababa', 'America/New_York', 'Pacific/Auckland'] as $tz) {
    $x = json_decode($child(['build', 'summary', '2025-03-01', '2025-03-31'], ['APP_TIMEZONE' => $tz]), true);
    t("$tz: identical report tables for the fixture window", ($x['tz'] ?? '') === $tz && ($x['sections'] ?? null) === ($base['sections'] ?? 'x'));
}
$cb = json_decode($child(['build', 'channels', '2025-03-31', '2025-03-31']), true);
t('single-day range 2025-03-31 includes the 23:59:59 interaction and nothing from 04-01', $cb['sections'][0]['rows'][1][1] === 1 && $cb['sections'][0]['rows'][0][1] === 0);

section('I. Auditing and data integrity');
t('CSV downloads are audited (one row each); views and the index are not', $auditN('export.csv') > $auditCsv0 && (function () use ($ADM, $auditN, $Q) { $n0 = $auditN('export.%'); $ADM->req('GET', "/reports?$Q"); $ADM->req('GET', "/reports/summary?$Q"); return $auditN('export.%') === $n0; })());
$a = $db->query("SELECT * FROM audit_logs WHERE action='export.csv' AND entity_id='campaigns' ORDER BY id DESC LIMIT 1")->fetch();
$nv = json_decode((string) $a['new_values'], true);
t('audit row: user, report type, period and row count recorded', (int) $a['user_id'] > 0 && $a['entity_type'] === 'report' && $nv['report'] === 'campaigns' && $nv['from'] === '2025-03-01' && $nv['to'] === '2025-03-31' && is_int($nv['rows']) && $nv['rows'] >= 1);
t('audit rows contain no exported personal data, campaign or service names', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'export.%' AND (CONCAT(IFNULL(old_values,''),IFNULL(new_values,'')) REGEXP 'Hiwot|Abel|0933|SECRET|HYPERLINK|SUM\\\\(')")->fetchColumn() === 0);
$nPrint0 = $auditN('export.print'); $MKT->req('GET', "/reports/summary/print?$Q");
t('print views are audited as export.print', $auditN('export.print') === $nPrint0 + 1);
$nBad = $auditN('export.%'); $ADM->req('GET', '/reports/summary/csv?range=bogus'); $REC->req('GET', "/reports/summary/csv?$Q");
t('rejected (422) and forbidden (403) requests write no audit row', $auditN('export.%') === $nBad);
t('generating reports changed no customer, appointment or history row', $snapshot() === $snapBefore);

section('J. Cleanup');
$db->exec("DELETE FROM interaction_appointments WHERE appointment_id IN ($AP1,$AP2)"); $db->exec("DELETE FROM appointment_events WHERE appointment_id IN ($AP1,$AP2)"); $db->exec("DELETE FROM appointments WHERE id IN ($AP1,$AP2)");
$db->exec("DELETE FROM interactions WHERE customer_id IN ($C1,$C2,$C3)"); $db->exec("DELETE FROM customers WHERE id IN ($C1,$C2,$C3)");
$db->exec("DELETE FROM campaigns WHERE id = $K"); $db->exec("DELETE FROM services WHERE id = $Z");
t('fixtures removed', $inWin('customers', 'first_acquisition_at') + $inWin('interactions', 'interaction_at') + $inWin('appointments', 'created_at') === 0);

finish('Phase 8 suite');
