<?php
declare(strict_types=1);
// Phase 3 suite: customer registration, search, duplicates, selection, permissions, audit, attribution.
require __DIR__ . '/lib.php';

use App\Helpers\Db;
use App\Services\CustomerService;

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $P3 = 'Lantern-Meadow-6204';
$role = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$src = array_column($db->query('SELECT name,id FROM acquisition_sources')->fetchAll(), 'id', 'name');
$plat = array_column($db->query('SELECT name,id FROM social_platforms')->fetchAll(), 'id', 'name');
$adminId = (int) $db->query("SELECT id FROM users WHERE username='boss'")->fetchColumn();
$hash = password_hash($P3, PASSWORD_BCRYPT, ['cost' => 12]);
foreach ([['p3_rec', 'receptionist'], ['p3_call', 'call_operator'], ['p3_soc', 'social_tracker'], ['p3_mkt', 'marketing']] as [$u, $r]) {
    $st = $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password, password_changed_at) VALUES (?,?,?,?,0,NOW())');
    $st->execute([ucfirst($r) . ' Tester', $u, $hash, $role[$r]]);
}
$login = function (string $u, string $p) { $c = new Client(); $c->login($u, $p); return $c; };
$REC = $login('p3_rec', $P3); $CALL = $login('p3_call', $P3); $SOC = $login('p3_soc', $P3); $MKT = $login('p3_mkt', $P3); $ADM = $login('boss', $ADMIN_PW);
$GOOGLE = $src['Google Maps'];

function tokens(string $html): array {
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $a); preg_match('/name="form_token" value="([a-f0-9]+)"/', $html, $b);
    return [$a[1] ?? '', $b[1] ?? ''];
}
function reg(Client $c, array $f, ?string $token = null): array {
    $r = $c->req('GET', '/customers/new'); [$csrf, $ft] = tokens($r['body']);
    return $c->req('POST', '/customers', $f + ['_csrf' => $csrf, 'form_token' => $token ?? $ft, 'ack_ids' => '']);
}
function cid(array $r): int { return preg_match('#/customers/(\d+)$#', $r['loc'], $m) ? (int) $m[1] : 0; }
$row = fn(int $id) => $db->query("SELECT * FROM customers WHERE id=$id")->fetch();
$ncust = fn() => (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$audit = fn(string $a) => (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action=" . $db->quote($a))->fetchColumn();
$base = ['name' => '', 'phone' => '', 'source_id' => $GOOGLE];

// ------------------------------------------------------------------ A. registration
section('A. Customer registration');
$r = $REC->req('GET', '/customers/new');
t('registration form renders with labels, logo and a form token', $r['status'] === 200 && str_contains($r['body'], 'for="name"') && str_contains($r['body'], 'for="phone"') && str_contains($r['body'], '/assets/images/babi-logo.jpg') && tokens($r['body'])[1] !== '');
t('page is responsive (viewport meta) and has no inline script/style/handlers (CSP-safe)', str_contains($r['body'], 'name="viewport"') && !preg_match('/<script(?![^>]*\bsrc=)|\sstyle=|\son[a-z]+=/i', $r['body']));
t('receptionist does not see a channel picker (set from role)', !str_contains($r['body'], 'id="channel"') && str_contains($r['body'], 'set automatically from your role'));

$r = reg($REC, ['name' => 'Selam Bekele', 'phone' => '0911 000 111'] + $base);
$A = cid($r);
t('name + phone registers and redirects to the customer', $r['status'] === 302 && $A > 0);
$a = $row($A);
t('customer code auto-generated (BDC-nnnnnn)', (bool) preg_match('/^BDC-\d{6}$/', $a['customer_code']));
t('stored: source, channel from role, acquisition time, creator', (int) $a['original_source_id'] === $GOOGLE && $a['first_acquisition_channel'] === 'reception' && $a['first_acquisition_at'] !== null && (int) $a['created_by_user_id'] === (int) $db->query("SELECT id FROM users WHERE username='p3_rec'")->fetchColumn());
t('phone normalised for matching (0911000111)', $a['phone_normalized'] === '0911000111' && $a['phone'] === '0911 000 111');
t('success message shown on the detail page', str_contains($REC->req('GET', "/customers/$A")['body'], 'Customer registered.'));

$r = reg($REC, ['name' => 'Name Only Person'] + $base); t('name only is accepted', $r['status'] === 302);
$id = cid($r); t('name-only record has no phone', $row($id)['phone'] === null && $row($id)['phone_normalized'] === null);
$r = reg($REC, ['phone' => '+251 911 000 222'] + $base); $id = cid($r);
t('phone only is accepted and +251 normalises to local form', $r['status'] === 302 && $row($id)['phone_normalized'] === '0911000222' && $row($id)['name'] === null);

$n0 = $ncust();
$r = reg($REC, $base);
t('neither name nor phone -> 422 with clear message', $r['status'] === 422 && str_contains($r['body'], 'name or telephone number'));
t('invalid phone characters rejected', reg($REC, ['phone' => 'abc-def'] + $base)['status'] === 422);
t('too-short phone rejected', reg($REC, ['phone' => '12345'] + $base)['status'] === 422);
t('1-character name rejected', reg($REC, ['name' => 'A'] + $base)['status'] === 422);
t('151-character name rejected', reg($REC, ['name' => str_repeat('a', 151)] + $base)['status'] === 422);
t('control characters in name rejected', reg($REC, ['name' => "Bad\x07Name"] + $base)['status'] === 422);
t('missing source rejected', reg($REC, ['name' => 'No Source', 'source_id' => ''])['status'] === 422);
t('non-existent source rejected', reg($REC, ['name' => 'Bad Source', 'source_id' => 99999])['status'] === 422);
$db->exec("UPDATE acquisition_sources SET is_active=0 WHERE name='Banner or poster'");
t('inactive source rejected', reg($REC, ['name' => 'Inactive Src', 'source_id' => $src['Banner or poster']])['status'] === 422);
$db->exec("UPDATE acquisition_sources SET is_active=1 WHERE name='Banner or poster'");
t('social media without a platform rejected', reg($REC, ['name' => 'No Platform', 'source_id' => $src['Social media']])['status'] === 422);
t('platform with a non-social source rejected', reg($REC, ['name' => 'Wrong Platform', 'platform_id' => $plat['Instagram']] + $base)['status'] === 422);
t('"other" description with a non-Other source rejected', reg($REC, ['name' => 'Wrong Other', 'source_other' => 'x'] + $base)['status'] === 422);
t('nothing was saved by the rejected submissions', $ncust() === $n0);
$r = reg($REC, ['name' => 'Keep Me', 'source_id' => '']);
t('entered values are preserved after a validation error', str_contains($r['body'], 'value="Keep Me"'));
$r = reg($REC, ['name' => 'Insta Person', 'source_id' => $src['Social media'], 'platform_id' => $plat['Instagram']]); $id = cid($r);
t('social media + platform stored', $r['status'] === 302 && (int) $row($id)['original_social_platform_id'] === $plat['Instagram']);
$r = reg($REC, ['name' => 'Other Person', 'source_id' => $src['Other'], 'source_other' => 'Saw it on a bus'] + []); $id = cid($r);
t('"Other" with description stored', $r['status'] === 302 && $row($id)['original_source_other'] === 'Saw it on a bus');
$r = reg($REC, ['name' => 'Unknown Src', 'source_id' => $src['Unknown / does not remember']]); $id = cid($r);
t('"Unknown" source is a valid answer', $r['status'] === 302 && (int) $row($id)['original_source_id'] === $src['Unknown / does not remember']);
$r = reg($REC, ['name' => 'Campaign Hack', 'campaign_id' => 999] + $base);
t('unknown campaign id rejected (never auto-assigned)', $r['status'] === 422);
$r = reg($REC, ['name' => 'No Campaign'] + $base); $id = cid($r);
t('no campaign is auto-assigned', $row($id)['original_campaign_id'] === null);

$r = reg($CALL, ['name' => 'Call Origin', 'channel' => 'reception'] + $base); $id = cid($r);
t('call operator: channel forced to phone even if form says reception', $row($id)['first_acquisition_channel'] === 'phone');
$r = reg($SOC, ['name' => 'Social Origin', 'channel' => 'phone'] + $base); $id = cid($r);
t('social tracker: channel forced to social_dm', $row($id)['first_acquisition_channel'] === 'social_dm');
$r = $ADM->req('GET', '/customers/new');
t('administrator sees a channel picker', str_contains($r['body'], 'id="channel"'));
t('administrator must choose a channel', reg($ADM, ['name' => 'Admin Made'] + $base)['status'] === 422);
$r = reg($ADM, ['name' => 'Admin Made', 'channel' => 'phone'] + $base); $id = cid($r);
t('administrator-chosen channel stored', $r['status'] === 302 && $row($id)['first_acquisition_channel'] === 'phone');

// double submit
$r0 = $REC->req('GET', '/customers/new'); [$cs, $ft] = tokens($r0['body']);
$n0 = $ncust();
$r1 = $REC->req('POST', '/customers', ['_csrf' => $cs, 'form_token' => $ft, 'ack_ids' => '', 'name' => 'Double Click', 'source_id' => $GOOGLE]);
$r2 = $REC->req('POST', '/customers', ['_csrf' => $cs, 'form_token' => $ft, 'ack_ids' => '', 'name' => 'Double Click', 'source_id' => $GOOGLE]);
t('double submission creates exactly one customer', $ncust() === $n0 + 1 && cid($r1) === cid($r2) && cid($r1) > 0);
$cs2 = tokens($CALL->req('GET', '/customers/new')['body'])[0];
t('a form token cannot be replayed by another user', $CALL->req('POST', '/customers', ['_csrf' => $cs2, 'form_token' => $ft, 'ack_ids' => '', 'name' => 'Double Click', 'source_id' => $GOOGLE])['status'] === 403 && $ncust() === $n0 + 1);
t('missing/invalid form token rejected', $REC->req('POST', '/customers', ['_csrf' => $cs, 'form_token' => 'zzz', 'name' => 'Tokenless', 'source_id' => $GOOGLE])['status'] === 422);
t('registration POST without CSRF rejected', $REC->req('POST', '/customers', ['form_token' => bin2hex(random_bytes(16)), 'name' => 'NoCsrf', 'source_id' => $GOOGLE])['status'] === 403);

// uniqueness under concurrency
$cl = []; for ($i = 0; $i < 8; $i++) { $cl[] = $login('p3_rec', $P3); }
$mh = curl_multi_init(); $hs = [];
foreach ($cl as $i => $c) {
    [$cs, $ft] = tokens($c->req('GET', '/customers/new')['body']);
    $ch = curl_init($c->base . '/customers');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $cs, 'form_token' => $ft, 'ack_ids' => '', 'name' => "Concurrent Person $i", 'source_id' => $GOOGLE]), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $c->jar, CURLOPT_COOKIEJAR => $c->jar]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $act); curl_multi_select($mh, 1); } while ($act > 0);
$okc = 0; foreach ($hs as $ch) { if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 302) $okc++; }
$codes = $db->query("SELECT customer_code FROM customers WHERE name LIKE 'Concurrent Person %'")->fetchAll(PDO::FETCH_COLUMN);
t('8 simultaneous registrations all succeed with 8 distinct customer IDs', $okc === 8 && count($codes) === 8 && count(array_unique($codes)) === 8);
t('all customer codes in the database are unique', (int) $db->query('SELECT COUNT(DISTINCT customer_code)-COUNT(*) FROM customers')->fetchColumn() === 0);
$r = reg($REC, ['name' => '<script>alert(1)</script>'] + $base); $id = cid($r);
$body = $REC->req('GET', "/customers/$id")['body'];
t('XSS payload in name is escaped on display', str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($body, '<script>alert(1)'));

// ------------------------------------------------------------------ B. duplicates
section('B. Duplicate detection');
$n0 = $ncust();
$dupF = ['name' => 'Hana Girma', 'phone' => '+251911000111'] + $base;
$r = reg($REC, $dupF);
t('same phone, different name -> warning page, nothing created', $r['status'] === 200 && str_contains($r['body'], 'Possible existing customer') && $ncust() === $n0);
t('warning shows enough detail to tell records apart (name, ID, date, source, channel)', str_contains($r['body'], 'Selam Bekele') && str_contains($r['body'], $a['customer_code']) && str_contains($r['body'], 'first heard via Google Maps') && str_contains($r['body'], 'Same phone number'));
t('warning masks the existing phone number', str_contains($r['body'], '••••••0111') && !str_contains($r['body'], '0911 000 111'));
t('warning offers "use this customer"', str_contains($r['body'], "/customers/$A/select"));
t('registration is not blocked: staff can confirm they are different people', str_contains($r['body'], 'register as a new customer'));
$wrong = reg($REC, $dupF + ['ack_ids' => '999999']);
$r = $REC->req('POST', '/customers', $dupF + ['_csrf' => tokens($REC->req('GET', '/customers/new')['body'])[0], 'form_token' => bin2hex(random_bytes(16)), 'ack_ids' => '999999']);
t('confirmation for the wrong record does not bypass the warning', $r['status'] === 200 && $ncust() === $n0);
$r = $REC->req('POST', '/customers', $dupF + ['_csrf' => tokens($REC->req('GET', '/customers/new')['body'])[0], 'form_token' => bin2hex(random_bytes(16)), 'ack_ids' => (string) $A]);
$B = cid($r);
t('confirming "different people" registers the second customer', $r['status'] === 302 && $B > 0 && $row($B)['phone_normalized'] === '0911000111');
t('two customers now legitimately share one phone number', (int) $db->query("SELECT COUNT(*) FROM customers WHERE phone_normalized='0911000111'")->fetchColumn() === 2);
t('override is audited with the matched customer ids', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='customer.duplicate_override' AND entity_id=$B AND new_values LIKE '%$A%'")->fetchColumn() === 1);
$r = reg($REC, ['name' => 'selam BEKELE'] + $base);
t('same name (case-insensitive), no phone -> warning', $r['status'] === 200 && str_contains($r['body'], 'Same name'));
$n1 = $ncust(); $r = reg($REC, ['name' => 'Completely Different', 'phone' => '0922 333 444'] + $base);
t('no match -> registers without any warning', $r['status'] === 302 && $ncust() === $n1 + 1);

// ------------------------------------------------------------------ C. search
section('C. Customer search');
$s = fn(Client $c, string $q, string $extra = '') => $c->req('GET', '/customers?q=' . urlencode($q) . $extra);
$rowsIn = fn(string $html) => preg_match_all('/<code class="small">BDC-\d+/', $html);
$r = $s($REC, $a['customer_code']);
t('search by customer ID', $r['status'] === 200 && $rowsIn($r['body']) === 1 && str_contains($r['body'], 'Selam Bekele'));
t('customer ID search is case/format tolerant (bdc-' . (int) substr($a['customer_code'], 4) . ')', $rowsIn($s($REC, 'bdc-' . (int) substr($a['customer_code'], 4))['body']) === 1);
t('unknown customer ID -> empty state with register link', str_contains($s($REC, 'BDC-888888')['body'], 'No customers match') && str_contains($s($REC, 'BDC-888888')['body'], '/customers/new'));
t('partial name (prefix) "sela"', str_contains($s($REC, 'sela')['body'], 'Selam Bekele'));
t('partial name (middle) "lam bek"', str_contains($s($REC, 'lam bek')['body'], 'Selam Bekele'));
t('word order does not matter ("bekele selam")', str_contains($s($REC, 'bekele selam')['body'], 'Selam Bekele'));
t('name search is case-insensitive', str_contains($s($REC, 'SELAM')['body'], 'Selam Bekele'));
$r = $s($REC, '000111');
t('partial phone finds both customers sharing the number', $rowsIn($r['body']) === 2 && str_contains($r['body'], 'Selam Bekele') && str_contains($r['body'], 'Hana Girma'));
t('shared phone numbers are flagged clearly', substr_count($r['body'], 'Shared with 1 other customer') === 2);
t('phone search works with country code and spaces', $rowsIn($s($REC, '+251 911 000 111')['body']) === 2);
t('results mask the phone number (last 4 digits only)', str_contains($r['body'], '••••••0111') && !str_contains($r['body'], '0911000111') && !str_contains($r['body'], '0911 000 111'));
t('results do not expose notes, staff names or raw ids of other records', !str_contains($r['body'], 'p3_') && !str_contains($r['body'], 'Call_operator Tester') && !str_contains($r['body'], 'Social_tracker Tester') && !str_contains($r['body'], 'Created by'));
t('3-digit phone query -> helpful message, no results', str_contains($s($REC, '091')['body'], 'at least 4 digits'));
t('1-letter name query -> helpful message', str_contains($s($REC, 'a')['body'], 'at least 2 letters'));
t('empty query -> hint only, no data dump', $rowsIn($REC->req('GET', '/customers')['body']) === 0);
t('no results empty state', str_contains($s($REC, 'zzzqqq')['body'], 'No customers match'));
// pagination
$ins = $db->prepare("INSERT INTO customers (customer_code, name, original_source_id, first_acquisition_channel, first_acquisition_at, created_by_user_id) VALUES (?,?,?,'phone',NOW(),?)");
for ($i = 1; $i <= 25; $i++) { $ins->execute([sprintf('BDC-9%05d', $i), sprintf('Pagetest %02d', $i), $GOOGLE, $adminId]); }
$p1 = $s($REC, 'Pagetest'); $p3 = $s($REC, 'Pagetest', '&page=3');
t('results are paginated (10 per page, 25 matches -> 3 pages)', $rowsIn($p1['body']) === 10 && str_contains($p1['body'], '25 matches') && str_contains($p1['body'], 'page 1 of 3') && $rowsIn($p3['body']) === 5);
t('page number beyond the end is clamped', str_contains($s($REC, 'Pagetest', '&page=999')['body'], 'page 3 of 3') && str_contains($s($REC, 'Pagetest', '&page=abc')['body'], 'page 1 of 3') && str_contains($s($REC, 'Pagetest', '&page=-4')['body'], 'page 1 of 3'));
t('pagination links keep the query', str_contains($p1['body'], 'q=Pagetest&amp;page=2'));
// lookup JSON
$r = $REC->req('GET', '/customers/lookup?q=' . urlencode('Pagetest'));
$j = json_decode($r['body'], true);
t('lookup returns JSON, max 8 rows, flags more results', $r['status'] === 200 && str_contains($r['headers'], 'application/json') && count($j['results']) === 8 && $j['more'] === true);
$r = $REC->req('GET', '/customers/lookup?q=000111'); $j = json_decode($r['body'], true);
t('lookup exposes only minimal fields with masked phone', count($j['results']) === 2 && $j['results'][0]['phone'] === '••••••0111' && !str_contains($r['body'], '0911') && !isset($j['results'][0]['notes']) && !isset($j['results'][0]['created_by']) && $j['results'][0]['shared_phone'] === 1);
t('lookup reports query problems instead of results', json_decode($REC->req('GET', '/customers/lookup?q=a')['body'], true)['message'] !== null);

// ------------------------------------------------------------------ D. SQL injection / wildcard abuse
section('D. SQL injection and wildcard abuse');
$n0 = $ncust(); $evil = ["' OR '1'='1", "%%", "S_lam", "Sel%", "\\", "x' UNION SELECT password_hash,2,3,4,5 FROM users -- ", "1; DROP TABLE customers; --", "\" OR 1=1 #", "BDC-1' OR '1'='1", "0911'; DELETE FROM customers; --", "%' OR 1=1 -- "];
$bad = 0; $leak = 0;
foreach ($evil as $q) {
    $r = $s($REC, $q); $l = $REC->req('GET', '/customers/lookup?q=' . urlencode($q));
    if ($r['status'] !== 200 || $l['status'] !== 200 || json_decode($l['body'], true) === null) $bad++;
    if (str_contains($r['body'], '$2y$') || str_contains($l['body'], '$2y$')) $leak++;
}
t('injection strings never cause errors (HTTP 200, valid JSON)', $bad === 0);
t('no password hashes or foreign data leak through search', $leak === 0);
t('customers table intact after injection attempts', $ncust() === $n0 && (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='babi_test' AND table_name='customers'")->fetchColumn() === 1);
t("LIKE wildcards are escaped ('S_lam' and 'Sel%' match nothing)", $rowsIn($s($REC, 'S_lam')['body']) === 0 && $rowsIn($s($REC, 'Sel%')['body']) === 0);
t('"%%" does not return every customer', $rowsIn($s($REC, '%%')['body']) === 0);
$r = reg($REC, ["name" => "Robert'); DROP TABLE customers;--", 'phone' => "0911'; --"] + $base);
t('SQL in the phone field is rejected by validation', $r['status'] === 422 && $ncust() === $n0);
$r = reg($REC, ['name' => "Robert'); DROP TABLE customers;--"] + $base); $id = cid($r);
t('SQL in the name is stored as plain text, harmlessly', $r['status'] === 302 && $row($id)['name'] === "Robert'); DROP TABLE customers;--" && $ncust() === $n0 + 1);
t('SQL in the customer-id path is a 404, not an error', $REC->req('GET', "/customers/1'%20OR%20'1'='1")['status'] === 404);
t('SQL in source_id is rejected', reg($REC, ['name' => 'Inj Src', 'source_id' => "1 OR 1=1"])['status'] === 422);

// ------------------------------------------------------------------ E. detail & channel visibility
section('E. Customer detail and data minimisation');
$db->exec("INSERT INTO interactions (customer_id,channel,interaction_reason,interaction_at,recorded_by_user_id) VALUES ($A,'reception','another_service',NOW(),$adminId)");
$db->exec("INSERT INTO interactions (customer_id,channel,interaction_reason,interaction_at,recorded_by_user_id,booking_outcome) VALUES ($A,'phone','appointment_followup',NOW(),$adminId,'confirmed')");
$db->exec("INSERT INTO interactions (customer_id,channel,interaction_reason,interaction_at,social_platform_id,recorded_by_user_id,booking_outcome) VALUES ($A,'social_dm','campaign_response',NOW(),{$plat['Instagram']},$adminId,'requested')");
$lab = ['Another service', 'Appointment / follow-up', 'Advertisement / campaign'];
$vis = fn(Client $c) => array_map(fn($l) => str_contains($c->req('GET', "/customers/$A")['body'], $l), $lab);
t('receptionist sees only reception interactions', $vis($REC) === [true, false, false]);
t('call operator sees only telephone interactions', $vis($CALL) === [false, true, false]);
t('social tracker sees only social DM interactions', $vis($SOC) === [false, false, true]);
t('administrator sees every channel', $vis($ADM) === [true, true, true]);
$d = $REC->req('GET', "/customers/$A")['body'];
t('detail shows contact, original acquisition and logo', str_contains($d, '0911 000 111') && str_contains($d, 'Original acquisition') && str_contains($d, 'Google Maps') && str_contains($d, '/assets/images/babi-logo.jpg'));
t('detail page has no administration controls for staff', !str_contains($d, 'Archive customer') && !str_contains($d, 'Correct original acquisition source'));
$db->exec("INSERT INTO customers (customer_code,name,original_source_id,first_acquisition_channel,first_acquisition_at,created_by_user_id,status) VALUES ('BDC-900100','Archived Person',$GOOGLE,'phone',NOW(),$adminId,'archived')");
$ARCH = (int) $db->query("SELECT id FROM customers WHERE customer_code='BDC-900100'")->fetchColumn();
t('archived customer: staff get 404 on detail', $REC->req('GET', "/customers/$ARCH")['status'] === 404);
t('archived customer is hidden from staff search and lookup', $rowsIn($s($REC, 'Archived Person')['body']) === 0 && json_decode($REC->req('GET', '/customers/lookup?q=Archived+Person')['body'], true)['results'] === []);
t('administrator can find archived customers only when asking for them', $rowsIn($s($ADM, 'Archived Person')['body']) === 0 && $rowsIn($s($ADM, 'Archived Person', '&archived=1')['body']) === 1);
t('non-admin cannot widen the search with archived=1', $rowsIn($s($REC, 'Archived Person', '&archived=1')['body']) === 0);
t('non-existent customer -> 404', $REC->req('GET', '/customers/99999999')['status'] === 404);

// ------------------------------------------------------------------ F. selection
section('F. Existing-customer selection (reusable picker)');
$dash = $REC->req('GET', '/reception')['body'];
t('dashboard embeds the reusable picker (markup + script)', str_contains($dash, 'data-picker') && str_contains($dash, 'customer-picker.js') && str_contains($dash, 'data-lookup-url="/customers/lookup"'));
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/customer-picker.js');
t('picker script builds DOM safely (no innerHTML/outerHTML assignment)', !preg_match('/\.(inner|outer)HTML\s*=/', $js) && !str_contains($js, 'document.write') && !str_contains($js, 'eval('));
t('picker script is served', $REC->req('GET', '/assets/js/customer-picker.js')['status'] === 200);
t('select without CSRF rejected', $REC->req('POST', "/customers/$A/select")['status'] === 403);
$r = $REC->post('/reception', "/customers/$A/select", []);
t('selecting an existing customer redirects to their record', $r['status'] === 302 && $r['loc'] === "/customers/$A");
t('selected customer is shown on every page for that user', str_contains($REC->req('GET', '/reception')['body'], 'Selected customer:') && str_contains($REC->req('GET', '/customers')['body'], $a['customer_code']));
t('selection is private to the session (other staff do not see it)', !str_contains($CALL->req('GET', '/call-operator')['body'], 'Selected customer:'));
t('selection never creates a duplicate customer or interaction', (int) $db->query("SELECT COUNT(*) FROM customers WHERE phone_normalized='0911000111'")->fetchColumn() === 2);
$REC->post('/reception', '/customers/selection/clear', []);
t('selection can be cleared', !str_contains($REC->req('GET', '/reception')['body'], 'Selected customer:'));
t('cannot select an archived customer', $REC->post('/reception', "/customers/$ARCH/select", [])['status'] === 404);
t('cannot select a non-existent customer', $REC->post('/reception', '/customers/99999999/select', [])['status'] === 404);
$REC->post('/reception', "/customers/$A/select", []);
$REC->post('/reception', '/logout', []); $REC->login('p3_rec', $P3);
t('selection ends with the session (logout)', !str_contains($REC->req('GET', '/reception')['body'], 'Selected customer:'));
$SOC->post('/social-media', "/customers/$B/select", []);
t('every channel role can select (social tracker)', str_contains($SOC->req('GET', '/social-media')['body'], 'Selected customer:'));
$CALL->post('/call-operator', "/customers/$B/select", []);
$ADM->post('/customers', "/customers/$B/archive", []);
t('archiving a customer drops them from staff selections', !str_contains($CALL->req('GET', '/call-operator')['body'], 'Selected customer:'));
$ADM->post('/customers', "/customers/$B/archive", []);
t('in-process: selectable() accepts active and rejects archived/unknown customers', (function () use ($A, $ARCH) { return CustomerService::selectable($A) !== null && CustomerService::selectable($ARCH) === null && CustomerService::selectable(99999999) === null; })());

// ------------------------------------------------------------------ G. authorization
section('G. Authorization');
$paths = ['/customers', '/customers/new', '/customers/lookup?q=selam', "/customers/$A"];
foreach ($paths as $p) { t("anonymous $p -> login", (new Client())->req('GET', $p)['loc'] === '/login'); }
foreach ($paths as $p) { t("marketing GET $p -> 403", $MKT->req('GET', $p)['status'] === 403); }
$n0 = $ncust(); $tk = $MKT->csrf('/marketing');
t('marketing cannot register customers (valid CSRF)', $MKT->req('POST', '/customers', ['_csrf' => $tk, 'form_token' => bin2hex(random_bytes(16)), 'name' => 'Mkt Made', 'source_id' => $GOOGLE])['status'] === 403 && $ncust() === $n0);
t('marketing cannot select customers', $MKT->req('POST', "/customers/$A/select", ['_csrf' => $tk])['status'] === 403);
t('marketing dashboard exposes no customer data', !str_contains($MKT->req('GET', '/marketing')['body'], 'Selam') && !str_contains($MKT->req('GET', '/marketing')['body'], '0911'));
foreach (['receptionist' => $REC, 'call_operator' => $CALL, 'social_tracker' => $SOC] as $rn => $c) {
    t("$rn can search, register and view", $c->req('GET', '/customers')['status'] === 200 && $c->req('GET', '/customers/new')['status'] === 200 && $c->req('GET', "/customers/$A")['status'] === 200);
    t("$rn cannot open edit / source-correction screens", $c->req('GET', "/customers/$A/edit")['status'] === 403 && $c->req('GET', "/customers/$A/source")['status'] === 403);
}
$before = $row($A); $tk = $REC->csrf('/reception');
t('staff cannot edit contact details (403, unchanged)', $REC->req('POST', "/customers/$A/edit", ['_csrf' => $tk, 'name' => 'Hacked', 'phone' => '0900000000'])['status'] === 403 && $row($A)['name'] === 'Selam Bekele');
t('staff cannot overwrite the original acquisition source (403, unchanged)', $REC->req('POST', "/customers/$A/source", ['_csrf' => $tk, 'source_id' => $src['Unknown / does not remember'], 'reason' => 'trying to rewrite history'])['status'] === 403 && (int) $row($A)['original_source_id'] === $GOOGLE);
t('staff cannot archive customers', $REC->req('POST', "/customers/$A/archive", ['_csrf' => $tk])['status'] === 403 && $row($A)['status'] === 'active');
t('registering with someone else\'s id/status fields cannot alter existing rows', (function () use ($REC, $A, $row, $before, $base) { reg($REC, ['name' => 'Mass Assign', 'id' => $A, 'status' => 'archived', 'original_source_id' => 1, 'customer_code' => $before['customer_code']] + $base); return $row($A) == $before; })());
t('there is no interaction delete route', $REC->req('POST', '/interactions/1/destroy', ['_csrf' => $tk])['status'] === 404);
t('the 24-hour interaction edit window setting is unchanged', $db->query("SELECT setting_value FROM settings WHERE setting_key='entry_edit_window_hours'")->fetchColumn() === '24');
$mix = array_filter(['name' => null]);
t('administrator can use search, register and detail screens', $ADM->req('GET', '/customers')['status'] === 200 && $ADM->req('GET', "/customers/$A")['status'] === 200 && str_contains($ADM->req('GET', "/customers/$A")['body'], 'Archive customer'));

// ------------------------------------------------------------------ H. admin corrections, attribution preserved
section('H. Corrections and original-acquisition protection');
$orig = $row($A);
$e = fn(array $f) => $ADM->post("/customers/$A/edit", "/customers/$A/edit", $f);
$n = $audit('customer.update');
$r = $e(['name' => 'Selam T. Bekele', 'phone' => '0911 000 111', 'source_id' => $src['Unknown / does not remember'], 'original_source_id' => 1, 'first_acquisition_at' => '2000-01-01 00:00:00']);
$now = $row($A);
t('admin corrects name; audit records which fields changed (no values)', $r['status'] === 302 && $now['name'] === 'Selam T. Bekele' && $audit('customer.update') === $n + 1 && str_contains((string) $db->query("SELECT new_values FROM audit_logs WHERE action='customer.update' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'changed_fields'));
t('contact edit never changes original acquisition even if extra fields are posted', $now['original_source_id'] === $orig['original_source_id'] && $now['first_acquisition_at'] === $orig['first_acquisition_at'] && $now['first_acquisition_channel'] === $orig['first_acquisition_channel']);
t('no-op edit writes no audit event', $e(['name' => 'Selam T. Bekele', 'phone' => '0911 000 111'])['status'] === 302 && $audit('customer.update') === $n + 1);
t('edit requires a name or phone', $e(['name' => '', 'phone' => ''])['status'] === 422);
t('database trigger blocks direct tampering with the original source', str_contains((string) throws($db, "UPDATE customers SET original_source_id={$src['Google Search']} WHERE id=$A"), 'write-once'));
$sc = fn(array $f) => $ADM->post("/customers/$A/source", "/customers/$A/source", $f);
t('source correction needs a written reason', $sc(['source_id' => $src['Friend or family referral']])['status'] === 422 && (int) $row($A)['original_source_id'] === $GOOGLE);
t('source correction with unchanged values is refused', $sc(['source_id' => $GOOGLE, 'reason' => 'no actual change here'])['status'] === 422);
$r = $sc(['source_id' => $src['Friend or family referral'], 'reason' => 'Customer clarified at the desk: a friend referred her']);
$now = $row($A);
t('audited correction changes the source', $r['status'] === 302 && (int) $now['original_source_id'] === $src['Friend or family referral']);
t('correction keeps first acquisition date and channel', $now['first_acquisition_at'] === $orig['first_acquisition_at'] && $now['first_acquisition_channel'] === $orig['first_acquisition_channel']);
$k = $db->query("SELECT * FROM customer_source_corrections WHERE customer_id=$A")->fetch();
t('correction row stores old source, new source, reason and who', (int) $k['old_source_id'] === $GOOGLE && (int) $k['new_source_id'] === $src['Friend or family referral'] && str_contains($k['reason'], 'friend referred') && (int) $k['corrected_by_user_id'] === $adminId);
t('audit event customer.source_correct holds old/new values and reason', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='customer.source_correct' AND entity_id=$A AND old_values LIKE '%\"source_id\":$GOOGLE%' AND new_values LIKE '%reason%'")->fetchColumn() === 1);
t('correction history is visible to the administrator', str_contains($ADM->req('GET', "/customers/$A")['body'], 'Google Maps') && str_contains($ADM->req('GET', "/customers/$A")['body'], 'Source correction'));
$actor = ['id' => $adminId, 'role_code' => 'admin'];
$res = CustomerService::correctSource($actor, $A, ['source_id' => $GOOGLE, 'reason' => 'reverting for the test run']);
$blocked = false; try { Db::pdo()->exec("UPDATE customers SET original_source_id={$src['Google Search']} WHERE id=$A"); } catch (PDOException $ex) { $blocked = str_contains($ex->getMessage(), 'write-once'); }
t('trigger is re-armed after a correction on the same connection', $res['ok'] && $blocked);
$ADM->post('/customers', "/customers/$A/archive", []);
t('archive is audited and hides the customer from staff', $audit('customer.archive') >= 1 && $REC->req('GET', "/customers/$A")['status'] === 404);
$ADM->post('/customers', "/customers/$A/archive", []);
t('restore is audited and the customer is visible again', $audit('customer.unarchive') >= 1 && $REC->req('GET', "/customers/$A")['status'] === 200);
$fin = $row($A);
t('after edit/archive/restore the original acquisition is intact', (int) $fin['original_source_id'] === $GOOGLE && $fin['first_acquisition_at'] === $orig['first_acquisition_at'] && $fin['first_acquisition_channel'] === $orig['first_acquisition_channel']);

// ------------------------------------------------------------------ I. audit & UI hygiene
section('I. Audit trail and interface hygiene');
foreach (['customer.create', 'customer.duplicate_override', 'customer.update', 'customer.archive', 'customer.unarchive', 'customer.source_correct'] as $ev) { t("audit event recorded: $ev", $audit($ev) >= 1); }
$created = (int) $db->query("SELECT COUNT(*) FROM customers c WHERE c.created_by_user_id IN (SELECT id FROM users WHERE username LIKE 'p3\\_%')")->fetchColumn();
t('every app-created customer has a customer.create audit event', $audit('customer.create') >= $created);
$all = (string) $db->query("SELECT GROUP_CONCAT(COALESCE(old_values,''),COALESCE(new_values,'')) FROM audit_logs WHERE action LIKE 'customer.%'")->fetchColumn();
t('audit payloads contain no names or phone numbers', !preg_match('/Selam|Hana|0911|Concurrent|Robert/', $all));
t('audit attributes creation to the acting user', (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='customer.create' AND user_id IS NULL")->fetchColumn() === 0);
$pages = ['/customers/new' => $ADM->req('GET', '/customers/new')['body'], '/customers?q=Pagetest' => $p1['body'], "/customers/$A" => $ADM->req('GET', "/customers/$A")['body'], "/customers/$A/source" => $ADM->req('GET', "/customers/$A/source")['body']];
foreach ($pages as $p => $html) {
    t("$p: viewport meta, labelled inputs, no inline script/style/handlers", str_contains($html, 'name="viewport"') && !preg_match('/<script(?![^>]*\bsrc=)|\sstyle=|\son[a-z]+=/i', $html) && (!str_contains($html, '<input type="text"') || str_contains($html, '<label')));
}
$r = $REC->req('GET', '/customers');
t('customer pages send CSP + no-store headers', str_contains($r['headers'], "Content-Security-Policy") && str_contains($r['headers'], 'no-store'));

finish('Phase 3 suite');
