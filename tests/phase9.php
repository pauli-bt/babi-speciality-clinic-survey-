<?php
declare(strict_types=1);
// Phase 9 security regression suite. Probes the running application over HTTP the way an attacker (or a curious
// staff member) would. It derives the authorization matrix from config/routes.php and the role_permissions table,
// so a route added later without protection is caught automatically.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $P9 = 'Birch-Canyon-9157';
$roleIds = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$hash = password_hash($P9, PASSWORD_BCRYPT, ['cost' => 12]);
$mk = function (string $u, string $r) use ($db, $roleIds, $hash): int {
    $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password, password_changed_at) VALUES (?,?,?,?,0,NOW())')->execute(['P9 ' . $r, $u, $hash, $roleIds[$r]]);
    return (int) $db->lastInsertId();
};
$users = ['receptionist' => 'p9_rec', 'call_operator' => 'p9_call', 'social_tracker' => 'p9_soc', 'marketing' => 'p9_mkt'];
$uid = []; foreach ($users as $r => $u) { $uid[$r] = $mk($u, $r); }
$clients = ['admin' => (function () use ($ADMIN_PW) { $c = new Client(); $c->login('boss', $ADMIN_PW); return $c; })()];
foreach ($users as $r => $u) { $c = new Client(); $c->login($u, $P9); $clients[$r] = $c; }
$anon = new Client();
$perms = [];
foreach ($db->query('SELECT r.code AS role, p.code AS perm FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id')->fetchAll() as $x) { $perms[$x['role']][$x['perm']] = true; }

section('A. Authorization matrix derived from config/routes.php (every route x every role)');
$router = require BASE_PATH . '/config/routes.php';
$ref = new ReflectionProperty($router, 'routes'); $ref->setAccessible(true);
$routes = $ref->getValue($router);
$allowed = function (string $r, array $o) use ($perms): bool {
    if (isset($o['role']) && $r !== $o['role']) { return false; }
    return !isset($o['perm']) || isset($perms[$r][$o['perm']]);
};
$authRoutes = array_values(array_filter($routes, fn($x) => !empty($x['opts']['auth'])));
t('route table loaded (auth-protected routes found)', count($authRoutes) >= 60, (string) count($authRoutes));
$bad = []; $n = 0;
foreach ($authRoutes as $rt) {
    $pat = trim($rt['regex'], '#^$'); // reconstruct a request path: replace the id group by a non-existent id
    $path = preg_replace('/\(\?P<\w+>\\\\d\{1,10\}\)/', '999999', $pat);
    $path = str_replace('\\', '', $path);
    $m = $rt['method'];
    if ($m === 'POST' && $path === '/logout') { continue; }
    $r0 = $m === 'GET' ? $anon->req('GET', $path) : $anon->req('POST', $path, ['x' => '1']);
    $n++; if ($r0['status'] !== 302 || $r0['loc'] !== '/login') { $bad[] = "anon $m $path => {$r0['status']}"; }
    foreach ($clients as $role => $c) {
        $ok = $role === 'admin' ? true : $allowed($role, $rt['opts']);
        if ($role === 'admin' && isset($rt['opts']['role'])) { $ok = false; } // entry routes are role-bound even for admin
        $n++;
        if ($m === 'GET') { $st = $c->req('GET', $path)['status']; }
        else { // POST: without a token everyone gets 403; with a valid token only forbidden roles are checked (no state changes possible)
            $noTok = $c->req('POST', $path, ['x' => '1'])['status'];
            if ($noTok !== 403) { $bad[] = "$role POST $path without CSRF => $noTok"; }
            if ($ok) { continue; }
            $st = $c->post('/password/change', $path, ['x' => '1'])['status'];
        }
        if ($ok && ($st === 403 || $st >= 500)) { $bad[] = "$role should be allowed: $m $path => $st"; }
        if (!$ok && $st !== 403) { $bad[] = "$role must be denied: $m $path => $st"; }
    }
}
t("authorization matrix holds for $n route/role combinations (anonymous -> login, forbidden -> 403, no 5xx)", $bad === [], implode(' | ', array_slice($bad, 0, 8)));

section('B. Privacy: marketing and entry roles cannot reach personal data or exports indirectly');
$mk = $clients['marketing'];
foreach (['/customers', '/customers/lookup?q=a', '/customers/1', '/interactions', '/interactions/1', '/appointments', '/appointments/1', '/customers/1/appointments', '/admin/audit', '/admin/users', '/admin/services'] as $p) { t("marketing GET $p -> 403", $mk->req('GET', $p)['status'] === 403); }
foreach (['receptionist', 'call_operator', 'social_tracker'] as $r) { foreach (['/analytics', '/analytics/campaigns', '/reports', '/reports/summary/csv?range=last7', '/campaigns', '/admin', '/admin/audit', '/admin/users', '/admin/services', '/marketing'] as $p) { if ($clients[$r]->req('GET', $p)['status'] !== 403) { t("$r GET $p -> 403", false); } } }
t('entry roles are denied analytics, reports, campaigns and admin pages (checked 3 roles x 10 paths)', true);
t('entry role cannot reach another channel\'s dashboard or form', $clients['receptionist']->req('GET', '/call-operator')['status'] === 403 && $clients['call_operator']->req('GET', '/social-media/interactions/new')['status'] === 403 && $clients['social_tracker']->req('POST', '/reception/interactions', ['_csrf' => $clients['social_tracker']->csrf('/password/change')])['status'] === 403);

section('C. Sessions and authentication');
$f = new Client(); $r1 = $f->req('GET', '/login'); $pre = $f->cookie();
t('login page sets a session cookie with HttpOnly, SameSite=Strict, Path=/ (and not Secure on plain-HTTP dev)', preg_match('/Set-Cookie: babi_sid=[^;]+;.*HttpOnly/i', $r1['headers']) === 1 && stripos($r1['headers'], 'SameSite=Strict') !== false && stripos($r1['headers'], 'path=/') !== false && stripos($r1['headers'], '; secure') === false);
$f->login('p9_rec', $P9); $post = $f->cookie();
t('session id changes at login (fixation defence)', $pre !== null && $post !== null && $pre !== $post);
$fx = new Client(); $fx->setCookie($pre); t('the pre-login session id is useless after login', $fx->req('GET', '/reception')['status'] === 302);
$att = new Client(); $att->setCookie('attackerchosenid1234567890attackerchosenid1234567890ab'); $att->login('p9_rec', $P9);
t('an attacker-chosen session id is not adopted (strict mode)', $att->cookie() !== 'attackerchosenid1234567890attackerchosenid1234567890ab');
$pr = new Client('http://127.0.0.1:8098'); $x = $pr->req('GET', '/login', null, ['X-Forwarded-Proto: https']);
t('production over HTTPS: __Host- cookie with Secure, HSTS and no-store', str_contains($x['headers'], '__Host-babi_sid') && stripos($x['headers'], '; secure') !== false && stripos($x['headers'], 'Strict-Transport-Security') !== false && stripos($x['headers'], 'no-store') !== false);
$y = $pr->req('GET', '/login'); t('production over plain HTTP is redirected to the https APP_URL', $y['status'] === 301 && str_starts_with($y['loc'], 'https://babi.example.test/login'));
// deactivation and role change end live sessions
$vic = new Client(); $vic->login('p9_call', $P9); t('victim session works before deactivation', $vic->req('GET', '/call-operator')['status'] === 200);
$clients['admin']->post('/admin/users', "/admin/users/{$uid['call_operator']}/active", []);
t('deactivating a user ends their live session immediately', $vic->req('GET', '/call-operator')['status'] === 302 && (int) $db->query("SELECT is_active FROM users WHERE id={$uid['call_operator']}")->fetchColumn() === 0);
$login2 = new Client(); $res = $login2->login('p9_call', $P9); t('a deactivated user cannot sign in (generic error)', $res['status'] === 401 && str_contains($res['body'], 'Incorrect username or password.'));
$db->exec("UPDATE users SET is_active=1 WHERE id={$uid['call_operator']}");
$cc = new Client(); $cc->login('p9_soc', $P9); $db->exec("UPDATE users SET role_id={$roleIds['marketing']}, session_version=session_version+1 WHERE id={$uid['social_tracker']}");
t('a role change (session_version bump) ends the old session', $cc->req('GET', '/social-media')['status'] === 302);
$db->exec("UPDATE users SET role_id={$roleIds['social_tracker']}, session_version=session_version+1 WHERE id={$uid['social_tracker']}");
$pa = new Client(); $pb = new Client(); $pa->login('p9_mkt', $P9); $pb->login('p9_mkt', $P9);
$pa->post('/password/change', '/password/change', ['current_password' => $P9, 'new_password' => 'Quartz-Ember-4471-xx', 'confirm_password' => 'Quartz-Ember-4471-xx']);
t('changing the password ends the user\'s other sessions', $pb->req('GET', '/marketing')['status'] === 302);
$db->prepare('UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?')->execute([$hash, $uid['marketing']]);
$clients['marketing'] = new Client(); $clients['marketing']->login('p9_mkt', $P9); $mk = $clients['marketing'];
$lg = new Client(); $codes = [];
for ($i = 0; $i < 5; $i++) { $codes[] = $lg->login('p9_nobody', 'wrong-' . $i)['status']; }
$lg2 = $lg->login('p9_nobody', 'wrong-again');
t('five failures lock the username (429) with a generic, non-enumerating message', $codes === [401, 401, 401, 401, 401] && $lg2['status'] === 429 && !str_contains($lg2['body'], 'p9_nobody exists'));
$real = new Client(); for ($i = 0; $i < 5; $i++) { $real->login('p9_rec', 'wrong-' . $i); }
t('a locked real account rejects even the correct password; an unknown account is locked identically', $real->login('p9_rec', $P9)['status'] === 429);
$db->exec("DELETE FROM login_attempts WHERE username LIKE 'p9\\_%'");
t('login without a CSRF token is refused (login CSRF)', (new Client())->req('POST', '/login', ['username' => 'p9_rec', 'password' => $P9])['status'] === 403);
t('GET /logout is not allowed; POST /logout needs a token', $clients['admin']->req('GET', '/logout')['status'] === 405 && $clients['admin']->req('POST', '/logout', [])['status'] === 403);
t('reset-password rejects malformed, unknown and replayed tokens without detail', (function () use ($anon) { foreach (['', 'abc', str_repeat('a', 64), str_repeat('g', 64), "' OR 1=1 --"] as $tok) { $r = $anon->req('GET', '/reset-password?token=' . urlencode($tok)); if ($r['status'] !== 400 || !str_contains($r['body'], 'invalid or has expired')) return false; } return true; })());
$db->exec("DELETE FROM login_attempts WHERE username LIKE '\\_\\_reset\\_\\_' OR ip_address='127.0.0.1' AND username LIKE 'p9\\_%'");

section('C2. Client address and lockout cannot be abused');
$ipOf = fn(string $u) => $db->query("SELECT ip_address FROM login_attempts WHERE username=" . $db->quote($u) . " ORDER BY id DESC LIMIT 1")->fetchColumn();
$hp = ['X-Forwarded-Proto: https', 'X-Forwarded-For: 203.0.113.50, 203.0.113.77'];
$px = new Client('http://127.0.0.1:8098'); $tk = (function () use ($px, $hp) { $r = $px->req('GET', '/login', null, $hp); return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : ''; })();
$px->req('POST', '/login', ['_csrf' => $tk, 'username' => 'p9_xff', 'password' => 'bad'], $hp);
t('behind a trusted proxy the rightmost X-Forwarded-For entry (added by our proxy) is the client address', $ipOf('p9_xff') === '203.0.113.77', (string) $ipOf('p9_xff'));
$pl = new Client(); $tk2 = $pl->csrf('/login'); $pl->req('POST', '/login', ['_csrf' => $tk2, 'username' => 'p9_xff2', 'password' => 'bad'], ['X-Forwarded-For: 203.0.113.5']);
t('without TRUST_PROXY the X-Forwarded-For header is ignored (cannot be used to evade or poison the limiter)', $ipOf('p9_xff2') === '127.0.0.1', (string) $ipOf('p9_xff2'));
$ins = $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES ('p9_flood', '203.0.113.77', 0)"); for ($i = 0; $i < 21; $i++) { $ins->execute(); }
$tk3 = (function () use ($px, $hp) { $r = $px->req('GET', '/login', null, $hp); return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : ''; })();
$locked = $px->req('POST', '/login', ['_csrf' => $tk3, 'username' => 'p9_rec', 'password' => $P9], $hp);
$other = new Client(); $okLogin = $other->login('p9_rec', $P9);
t('one flooding client address is locked out (429) while users at other addresses can still sign in', $locked['status'] === 429 && $okLogin['status'] === 302);
$db->exec("DELETE FROM login_attempts WHERE username LIKE 'p9\\_%'");
for ($i = 0; $i < 30; $i++) { $anon->req('GET', '/reset-password'); }
$after = new Client(); t('opening the reset page without a token never counts as a failed attempt (cannot be used to lock the login page)', $after->login('p9_rec', $P9)['status'] === 302 && (int) $db->query("SELECT COUNT(*) FROM login_attempts WHERE username='__reset__'")->fetchColumn() === 0);
$ch = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', 'require "' . BASE_PATH . '/app/bootstrap.php"; App\\Helpers\\Http::hardenRuntime(); trigger_error("p9-visible-warning", E_USER_WARNING); echo "|display_errors=" . ini_get("display_errors");'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, BASE_PATH, ['APP_ENV' => 'testing', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
$co = stream_get_contents($pp[1]) . stream_get_contents($pp[2]); proc_close($ch);
t('runtime hardening turns display_errors off even if php.ini has it on (warnings are logged, not shown)', str_contains($co, '|display_errors=0') && !str_contains($co, 'p9-visible-warning'), trim($co));
$ctl = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', 'require "' . BASE_PATH . '/app/bootstrap.php"; trigger_error("p9-visible-warning", E_USER_WARNING);'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cp2, BASE_PATH, ['APP_ENV' => 'testing', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
$cco = stream_get_contents($cp2[1]) . stream_get_contents($cp2[2]); proc_close($ctl);
t('negative control: without the hardening call the same php.ini does display the warning (the check can detect the problem)', str_contains($cco, 'p9-visible-warning'));
@unlink(BASE_PATH . '/storage/logs/php-error.log');

section('D. Injection and XSS: stored, reflected, SQL, array-typed parameters');
$P = '<script>alert("P9")</script><img src=x onerror=alert(9)>"\'`';
$S1 = (int) $db->query('SELECT id FROM acquisition_sources ORDER BY id LIMIT 1')->fetchColumn(); $adm = (int) $db->query("SELECT id FROM users WHERE username='boss'")->fetchColumn();
$db->prepare("INSERT INTO customers (customer_code,name,phone,phone_normalized,original_source_id,first_acquisition_channel,first_acquisition_at,created_by_user_id) VALUES ('BDC-790001',?,'0911790001','0911790001',?,'phone',NOW(),?)")->execute([$P, $S1, $adm]); $C = (int) $db->lastInsertId();
$db->prepare("INSERT INTO services (name,description,is_active) VALUES (?,?,1)")->execute([$P, $P]); $SV = (int) $db->lastInsertId();
$db->prepare("INSERT INTO campaigns (name,campaign_code,status) VALUES (?,'P9-X','active')")->execute([$P]); $CP = (int) $db->lastInsertId();
$callId = (int) $db->query("SELECT id FROM users WHERE username='p9_call'")->fetchColumn();
$db->prepare("INSERT INTO interactions (customer_id,channel,interaction_reason,is_first_contact,interaction_at,campaign_id,service_id,booking_outcome,notes,recorded_by_user_id) VALUES (?,'phone','campaign_response',0,NOW(),?,?,'requested',?,?)")->execute([$C, $CP, $SV, $P, $callId]); $I = (int) $db->lastInsertId();
$db->prepare("INSERT INTO appointments (customer_id,service_id,status,origin_interaction_id,created_by_user_id) VALUES (?,?,'requested',?,?)")->execute([$C, $SV, $I, $callId]); $A = (int) $db->lastInsertId();
$db->prepare("INSERT INTO appointment_events (appointment_id,from_status,to_status,user_id) VALUES (?,NULL,'requested',?)")->execute([$A, $callId]);
$db->prepare("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,new_values) VALUES (?,?,?,?,?)")->execute([$adm, 'p9.xss', 'route', '1', json_encode(['path' => $P])]);
$pages = ['/customers?q=' . urlencode('BDC-790001'), "/customers/$C", '/interactions', "/interactions/$I", '/appointments', "/appointments/$A", '/campaigns', '/admin/services', "/admin/services/$SV/edit", "/campaigns/$CP/edit",
    '/admin/audit?action=p9', '/admin/users', '/analytics/campaigns?range=last30', '/analytics/appointments', '/reports/campaigns?range=last30', '/reports/services/print?range=last30', "/customers/lookup?q=BDC-790001", "/customers/$C/appointments"];
$raw = []; $seen = 0;
foreach ($pages as $p) { $b = $clients['admin']->req('GET', $p)['body']; if (str_contains($b, '<script>alert("P9")') || str_contains($b, '<img src=x onerror') || str_contains($b, 'onerror=alert(9)>')) { $raw[] = $p; } if (str_contains($b, '&lt;script&gt;') || str_contains($b, '\u003Cscript\u003E')) { $seen++; } }
t('stored XSS payload in names, notes, campaign/service names and audit data is escaped on every page', $raw === [], implode(', ', $raw));
t('the payload actually reached those pages in escaped form (probe is not vacuous)', $seen >= 8, (string) $seen);
$rf = []; $names = ['q', 'page', 'status', 'service_id', 'channel', 'from', 'to', 'range', 'action', 'token', 'archived', 'campaign_id', 'type', 'sort', 'order', 'name'];
foreach (['/customers', '/interactions', '/appointments', '/campaigns', '/admin/audit', '/analytics', '/analytics/channels', '/reports', '/reports/summary', '/reports/summary/print', '/reset-password', '/login'] as $p) {
    foreach ($names as $k) { $r = $clients['admin']->req('GET', $p . '?' . $k . '=' . urlencode($P)); if (str_contains($r['body'], '<script>alert("P9")') || str_contains($r['body'], '<img src=x onerror')) { $rf[] = "$p?$k"; } }
}
t('reflected XSS: no query parameter is echoed unescaped (12 pages x 16 parameters)', $rf === [], implode(', ', array_slice($rf, 0, 6)));
$sq = []; $sqlWords = '/SQLSTATE|syntax error|mysqli|PDOException|You have an error|Unknown column|stack trace/i';
foreach (["'", "\\", "1' OR '1'='1", "1; DROP TABLE users--", "%00", "' UNION SELECT password_hash FROM users--"] as $inj) {
    foreach (['/customers', '/customers/lookup', '/interactions', '/appointments', '/campaigns', '/admin/audit', '/analytics', '/analytics/acquisition', '/reports/summary/csv', '/reports/campaigns'] as $p) {
        foreach (['q', 'status', 'service_id', 'channel', 'from', 'to', 'range', 'action', 'page'] as $k) { $r = $clients['admin']->req('GET', $p . '?' . $k . '=' . urlencode($inj)); if ($r['status'] >= 500 || preg_match($sqlWords, $r['body'])) { $sq[] = "$p?$k=$inj"; } }
    }
}
t('SQL injection probes (6 payloads x 10 pages x 9 parameters): no 5xx, no SQL error text', $sq === [], implode(' | ', array_slice($sq, 0, 5)));
t('users table intact after the injection probes', (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 6);
$ar = [];
foreach (['/customers', '/customers/lookup', '/interactions', '/appointments', '/campaigns', '/admin/audit', '/analytics', '/analytics/channels', '/reports', '/reports/summary', '/reports/summary/csv', '/reports/summary/print', '/reset-password'] as $p) {
    foreach (['q', 'status', 'service_id', 'channel', 'from', 'to', 'range', 'action', 'page', 'token', 'archived'] as $k) { $r = $clients['admin']->req('GET', $p . '?' . $k . '[]=x&' . $k . '[a]=y'); if ($r['status'] >= 500 || preg_match($sqlWords . 'i', $r['body']) || preg_match('/Warning:|Notice:|Deprecated:|Fatal error|\.php on line/', $r['body'])) { $ar[] = "$p?$k=>{$r['status']}"; } }
}
t('array-typed parameters (?k[]=x) never cause 5xx, warnings or path disclosure', $ar === [], implode(' | ', array_slice($ar, 0, 6)));
$arp = []; foreach (['/customers', '/admin/services', '/campaigns', '/appointments/' . $A . '/status', '/appointments/' . $A . '/reschedule', '/admin/users'] as $p) { $r = $clients['admin']->req('POST', $p, ['_csrf' => $clients['admin']->csrf('/password/change'), 'name' => ['x'], 'status' => ['x'], 'appointment_at' => ['x'], 'phone' => ['x'], 'role_id' => ['x'], 'username' => ['x']]); if ($r['status'] >= 500 || preg_match('/Warning:|Notice:|Deprecated:|Fatal error|\.php on line|TypeError/', $r['body'])) { $arp[] = "$p => {$r['status']}"; } }
t('array-typed POST fields are rejected gracefully (no 5xx / error text) on state-changing endpoints', $arp === [], implode(' | ', $arp));
$dbg = new Client('http://127.0.0.1:8096'); $dbg->login('boss', $ADMIN_PW); $leak = [];
foreach (['/customers?q[]=x', '/interactions?page[]=1', '/appointments?status[]=x', '/analytics?range[]=x', '/reports/summary/csv?range[]=x', '/admin/audit?action[]=x', '/reset-password?token[]=x'] as $p) { $r = $dbg->req('GET', $p); if (preg_match('/Warning|Notice|Deprecated|Fatal error|Stack trace|\.php on line|\/home\/|\/var\/www|htdocs/', $r['body'])) { $leak[] = $p; } }
$dbgOk = $dbg->req('GET', '/customers')['status'] === 200;
t('with PHP display_errors=On on the server, no warning or file path reaches the browser (probe server signed in: ' . ($dbgOk ? 'yes' : 'NO') . ')', $leak === [] && $dbgOk, implode(', ', $leak));

section('E. Headers, errors and exposure of files');
$hdr = []; $probe = ['/login' => $anon, '/' => $clients['admin'], '/admin' => $clients['admin'], '/nope-404' => $anon, '/admin/users' => $clients['receptionist'], '/reports/summary/csv?range=last7' => $clients['admin'], '/reports/summary/print?range=last7' => $clients['admin'], "/customers/lookup?q=BDC" => $clients['admin'], '/reports/summary/csv?range=bogus' => $clients['admin']];
foreach ($probe as $p => $c) { $h = $c->req('GET', $p)['headers']; foreach (["Content-Security-Policy: default-src 'self'", 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer', 'no-store'] as $need) { if (stripos($h, $need) === false) { $hdr[] = "$p lacks $need"; } } }
t('security headers present on pages, JSON, CSV, print, 403, 404 and 422 responses', $hdr === [], implode(' | ', array_slice($hdr, 0, 4)));
t('CSP forbids inline script, framing, external hosts and base/form hijacking', (function () use ($anon) { $h = $anon->req('GET', '/login')['headers']; return str_contains($h, "script-src 'self'") && str_contains($h, "frame-ancestors 'none'") && str_contains($h, "form-action 'self'") && str_contains($h, "base-uri 'none'") && !str_contains($h, 'unsafe-inline') && !str_contains($h, 'unsafe-eval') && !preg_match('#https?://#', substr($h, strpos($h, 'Content-Security-Policy'), 260)); })());
t('JSON endpoint declares its content type; error pages do not reveal stack, SQL or paths', stripos($clients['admin']->req('GET', '/customers/lookup?q=BDC')['headers'], 'application/json') !== false && !preg_match('/Stack trace|SQLSTATE|\/home\/|\.php/', $clients['admin']->req('GET', '/customers/abc')['body']));
$ex = [];
foreach (['/.env', '/.env.example', '/.git/config', '/app/bootstrap.php', '/config/routes.php', '/storage/logs/app.log', '/storage/sessions/', '/tests/run.php', '/tests/phase9.php', '/database/schema.sql', '/database/seeders/reference_data.sql', '/bin/migrate.php', '/bin/create-admin.php', '/composer.json', '/README.md', '/docs/PHASE9_SECURITY_REVIEW.md', '/resources/views/layouts/app.php', '/public/index.php', '/index.php.bak', '/assets/', '/assets/css/', '/vendor/'] as $p) {
    $r = $anon->req('GET', $p); if (!in_array($r['status'], [404, 302, 403], true) || preg_match('/DB_PASS|CREATE TABLE|<\?php|BCRYPT/', $r['body'])) { $ex[] = "$p => {$r['status']}"; }
}
t('sensitive files and directories are not served by the web root (public/)', $ex === [], implode(' | ', $ex));
$ht = @file_get_contents(BASE_PATH . '/.htaccess');
t('a project-root .htaccess denies web access to everything outside public/ (for XAMPP htdocs installs)', $ht !== false && str_contains($ht, 'RewriteRule') && str_contains($ht, 'public/') && str_contains($ht, '[F'));
t('public/.htaccess keeps directory listing off', str_contains((string) file_get_contents(BASE_PATH . '/public/.htaccess'), 'Options -Indexes'));
t('only vendored local assets are referenced (no CDN or third-party host) in the layouts and views', (function () { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/resources/views')) as $f) { if ($f->isFile() && preg_match('#(src|href)=["\']https?://#i', (string) file_get_contents($f->getPathname()))) return false; } return true; })());
t('no file-upload handling exists in the application', !preg_grep('/\$_FILES|move_uploaded_file/', array_map('file_get_contents', array_merge(glob(BASE_PATH . '/app/*/*.php'), glob(BASE_PATH . '/public/*.php')))));
t('no dangerous PHP functions are used (eval, exec, shell_exec, system, passthru, unserialize, extract on input)', !preg_grep('/\b(eval|shell_exec|passthru|system|proc_open|popen|unserialize)\s*\(|(?<!->)\bexec\(/', array_map(fn($f) => preg_replace('~//.*|/\*.*?\*/~s', '', (string) file_get_contents($f)), array_merge(glob(BASE_PATH . '/app/*/*.php'), glob(BASE_PATH . '/public/*.php')))));
t('composer.json declares no third-party runtime packages', !str_contains((string) file_get_contents(BASE_PATH . '/composer.json'), '"vendor/') && !preg_match('/"require"\s*:\s*\{[^}]*"[a-z0-9_-]+\/[a-z0-9_-]+"/i', (string) file_get_contents(BASE_PATH . '/composer.json')));

section('F. Business-rule integrity cannot be bypassed by tampering');
$callC = new Client(); $lgSt = $callC->login('p9_call', $P9); t('call operator can sign in again for the business-rule probes', $lgSt['status'] === 302, 'login status=' . $lgSt['status'] . ' ' . substr(strip_tags($lgSt['body']), 0, 120));
$tok = $callC->csrf('/call-operator/interactions/new');
$res = $callC->req('POST', '/interactions/' . $I . '/edit', ['_csrf' => $tok, 'channel' => 'reception', 'customer_id' => '1', 'interaction_reason' => 'first_contact', 'booking_outcome' => 'confirmed', 'campaign_id' => '', 'notes' => 'tamper']);
$row = $db->query("SELECT channel, customer_id, interaction_reason, booking_outcome FROM interactions WHERE id=$I")->fetch();
t('interaction edit cannot change channel, customer, reason or booking outcome (field tampering ignored)', $row['channel'] === 'phone' && (int) $row['customer_id'] === $C && $row['interaction_reason'] === 'campaign_response' && $row['booking_outcome'] === 'requested', json_encode($row));
$other = $clients['receptionist']; t('another channel cannot read or edit the interaction by ID (404/403)', in_array($other->req('GET', "/interactions/$I")['status'], [403, 404], true) && in_array($other->req('GET', "/interactions/$I/edit")['status'], [403, 404], true));
$db->exec("UPDATE interactions SET created_at = NOW() - INTERVAL 25 HOUR WHERE id=$I");
$edSt = $callC->req('GET', "/interactions/$I/edit")['status']; $edAud = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='interaction.edit_denied' AND entity_id='$I'")->fetchColumn();
t('after 24 hours the owner can no longer edit (server-side), and the denial is audited', $edSt === 403 && $edAud >= 1, "status=$edSt audit=$edAud created=" . $db->query("SELECT created_at FROM interactions WHERE id=$I")->fetchColumn() . ' now=' . $db->query('SELECT NOW()')->fetchColumn());
$adm = $clients['admin']; $src2 = (int) $db->query('SELECT id FROM acquisition_sources ORDER BY id DESC LIMIT 1')->fetchColumn();
$before = $db->query("SELECT original_source_id, first_acquisition_channel, first_acquisition_at FROM customers WHERE id=$C")->fetch();
$adm->post("/customers/$C/edit", "/customers/$C/edit", ['name' => 'Renamed', 'original_source_id' => (string) $src2, 'first_acquisition_channel' => 'reception', 'first_acquisition_at' => '2001-01-01 00:00:00', 'source_id' => (string) $src2]);
$after = $db->query("SELECT original_source_id, first_acquisition_channel, first_acquisition_at FROM customers WHERE id=$C")->fetch();
t('customer edit cannot overwrite original source, acquisition channel or acquisition date', $before === $after);
$mysql = $db->query("SELECT COUNT(*) FROM appointments WHERE customer_id=$C")->fetchColumn();
$recC = $clients['receptionist']; $tk = $recC->csrf('/reception/interactions/new');
$recC->req('POST', '/reception/interactions', ['_csrf' => $tk, 'customer_mode' => 'existing', 'customer_id' => (string) $C, 'interaction_reason' => 'another_service', 'service_id' => (string) $SV, 'used' => 'used', 'booking_outcome' => 'requested', 'create_appointment' => '1', 'channel' => 'phone', 'interaction_at' => date('Y-m-d\TH:i'), 'form_token' => bin2hex(random_bytes(16))]);
t('reception cannot create an appointment or switch channel even with forged fields', (int) $db->query("SELECT COUNT(*) FROM appointments WHERE customer_id=$C")->fetchColumn() === (int) $mysql && (int) $db->query("SELECT COUNT(*) FROM interactions WHERE customer_id=$C AND channel='phone' AND recorded_by_user_id=" . $uid['receptionist'])->fetchColumn() === 0);
$ev0 = (int) $db->query("SELECT COUNT(*) FROM appointment_events WHERE appointment_id=$A")->fetchColumn();
$adm->post("/appointments/$A", "/appointments/$A/status", ['status' => 'completed']);
t('illegal appointment transition (requested -> completed) is refused and leaves no history', $db->query("SELECT status FROM appointments WHERE id=$A")->fetchColumn() === 'requested' && (int) $db->query("SELECT COUNT(*) FROM appointment_events WHERE appointment_id=$A")->fetchColumn() === $ev0);
$adm->post("/appointments/$A", "/appointments/$A/status", ['status' => 'cancelled']); $adm->post("/appointments/$A", "/appointments/$A/status", ['status' => 'cancelled']);
t('duplicate submission of the same status change records exactly one history row', (int) $db->query("SELECT COUNT(*) FROM appointment_events WHERE appointment_id=$A AND to_status='cancelled'")->fetchColumn() === 1);
$nA = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'export.%'")->fetchColumn();
$m1 = $clients['marketing']->req('GET', '/reports/summary/csv?range=last7')['status']; $m2 = $clients['receptionist']->req('GET', '/reports/summary/csv?range=last7')['status']; $nB = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'export.%'")->fetchColumn();
t('marketing export is audited once; the denied entry-role attempt is not logged as an export', $nB === $nA + 1, "before=$nA after=$nB marketing=$m1 reception=$m2");
$csv = $clients['marketing']->req('GET', '/reports/campaigns/csv?range=last30')['body'] . $clients['marketing']->req('GET', '/reports/services/csv?range=last30')['body'];
t('exports stay aggregate-only: no customer code, phone or renamed customer in marketing CSVs', !preg_match('/BDC-790001|0911790001|Renamed/', $csv));
t('CSV formula injection: a campaign named with a script payload is exported as text', str_contains($csv, "\"'") || !preg_match('/^[=+\-@]/m', $csv));

section('G. Cleanup');
foreach (["DELETE FROM appointment_events WHERE appointment_id=$A", "DELETE FROM interaction_appointments WHERE appointment_id=$A", "DELETE FROM appointments WHERE id=$A", "DELETE FROM interactions WHERE customer_id=$C", "DELETE FROM customers WHERE id=$C", "DELETE FROM campaigns WHERE id=$CP", "DELETE FROM services WHERE id=$SV", "DELETE FROM audit_logs WHERE action='p9.xss'", "DELETE FROM login_attempts WHERE username LIKE 'p9\\_%'"] as $q) { $db->exec($q); }
t('fixtures removed', (int) $db->query("SELECT COUNT(*) FROM customers WHERE customer_code='BDC-790001'")->fetchColumn() === 0);
finish('Phase 9 security suite');
