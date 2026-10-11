<?php
declare(strict_types=1);
// Phase 1 + 2 suite (kept as the regression suite): migrations, auth, authorization, sessions, accounts.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$ADMIN_PW = 'Harbor-Lantern-5927'; $TEMP = 'Tempo-Clinic-4821x'; $FINAL = 'Sunrise-Harbor-7391';
$role = array_column($db->query('SELECT code,id FROM roles')->fetchAll(), 'id', 'code');
$audit = fn(string $a) => (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action=" . $db->quote($a))->fetchColumn();

// ---------------------------------------------------------------- A. migrations
section('A. Migrations preserve Phase 1 data and rules');
$leg = admin_pdo('babi_migtest');
$cnt = fn(string $tb) => (int) $leg->query("SELECT COUNT(*) FROM $tb")->fetchColumn();
t('existing customers kept', $cnt('customers') === 1);
t('existing interactions kept', $cnt('interactions') === 2);
t('existing appointment kept', $cnt('appointments') === 1);
t('existing user kept, session_version defaulted to 1', (int) $leg->query("SELECT session_version FROM users WHERE username='legacy'")->fetchColumn() === 1);
t('new auth columns added', (int) $leg->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='babi_migtest' AND table_name='users' AND column_name IN ('password_changed_at','session_version','created_by_user_id')")->fetchColumn() === 3);
t('baseline detected on legacy DB (no reload)', str_contains((string) file_get_contents('/tmp/migrate_legacy.log'), 'data left untouched'));
t('migrations recorded', $leg->query("SELECT GROUP_CONCAT(version ORDER BY version) FROM schema_migrations")->fetchColumn() === '001_phase1_baseline,002_phase2_auth,003_phase3_customers,004_phase4_interactions');
t('Phase 3 migration kept legacy data and continued the customer-code sequence', (int) $leg->query('SELECT next_value FROM customer_code_counter')->fetchColumn() === 1 && $cnt('customers') === 1 && (int) $leg->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='babi_migtest' AND table_name='customers' AND column_name='submission_token'")->fetchColumn() === 1);
t('re-running migrate is a no-op', !str_contains((string) file_get_contents('/tmp/migrate_legacy2.log'), 'migrating:'));
t('fresh DB gets baseline + migration', str_contains((string) file_get_contents('/tmp/migrate_fresh.log'), 'loading /schema.sql') && str_contains((string) file_get_contents('/tmp/migrate_fresh.log'), '002_phase2_auth'));
t('write-once acquisition trigger still active', str_contains((string) throws($leg, 'UPDATE customers SET original_source_id=3 WHERE id=1'), 'write-once'));
t('interaction channel immutable', str_contains((string) throws($leg, "UPDATE interactions SET channel='reception' WHERE id=1"), 'immutable'));
t('reception cannot carry booking outcome (CHECK)', throws($leg, "INSERT INTO interactions(customer_id,channel,interaction_reason,interaction_at,recorded_by_user_id,booking_outcome) VALUES(1,'reception','another_service',NOW(),1,'confirmed')") !== null);
t('phone cannot carry service usage (CHECK)', throws($leg, "INSERT INTO interactions(customer_id,channel,interaction_reason,interaction_at,recorded_by_user_id,service_usage_outcome) VALUES(1,'phone','another_service',NOW(),1,'used')") !== null);
t('one interaction -> one booking (UNIQUE origin)', throws($leg, "INSERT INTO appointments(customer_id,service_id,origin_interaction_id,created_by_user_id) VALUES(1,1,1,1)") !== null);
t('runtime DB account cannot run DDL', (function () { global $PW; $p = new PDO('mysql:host=localhost;dbname=babi_test', 'babi_app', $PW, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); return throws($p, 'CREATE TABLE x_should_fail (a INT)') !== null; })());

// ---------------------------------------------------------------- B. password policy
section('B. Password policy');
use App\Validation\PasswordPolicy as PP;
t('too short rejected', PP::validate('Ab1-short') !== []);
t('no digit rejected', PP::validate('Onlyletters-here-long') !== []);
t('no letter rejected', PP::validate('1234567-890123456') !== []);
t('contains username rejected', PP::validate('marta-River-9912', 'marta') !== []);
t('common word rejected', PP::validate('MyPassword-2024-xx') !== []);
t('over 72 bytes rejected', PP::validate(str_repeat('aB3', 30)) !== []);
t('good passphrase accepted', PP::validate('Sunrise-Harbor-7391', 'someone') === []);
t('generated passwords satisfy policy', (function () { for ($i = 0; $i < 20; $i++) { $p = PP::generate('x'); if (PP::validate($p, 'x') !== []) return false; } return true; })());

// ---------------------------------------------------------------- C. first administrator
section('C. Safe administrator initialisation');
$run = fn(string $env) => shell_exec("cd " . escapeshellarg(dirname(__DIR__)) . " && $env php bin/create-admin.php 2>&1");
$o = $run("BABI_ADMIN_USERNAME=boss BABI_ADMIN_NAME='Clinic Admin' BABI_ADMIN_PASSWORD=short1");
t('weak admin password refused', str_contains((string) $o, 'ERROR') && (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0);
$o = $run("BABI_ADMIN_USERNAME=boss BABI_ADMIN_NAME='Clinic Admin' BABI_ADMIN_PASSWORD=$ADMIN_PW");
t('first administrator created', str_contains((string) $o, 'created') && (int) $db->query("SELECT COUNT(*) FROM users WHERE username='boss'")->fetchColumn() === 1);
$o = $run("BABI_ADMIN_USERNAME=boss2 BABI_ADMIN_NAME='Second' BABI_ADMIN_PASSWORD=$ADMIN_PW");
t('second bootstrap refused once an admin exists', str_contains((string) $o, 'already exists') && (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
$h = (string) $db->query("SELECT password_hash FROM users WHERE username='boss'")->fetchColumn();
t('password stored as bcrypt hash, not plaintext', str_starts_with($h, '$2y$') && !str_contains($h, $ADMIN_PW) && password_verify($ADMIN_PW, $h));
t('bootstrap audited', $audit('user.bootstrap_admin') === 1);
$adminId = (int) $db->query("SELECT id FROM users WHERE username='boss'")->fetchColumn();
use App\Services\UserService as US;
$r = US::setActive(['id' => 999999], $adminId, false);
t('last active administrator cannot be deactivated', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'last active administrator'));
$r = US::update(['id' => 999999], $adminId, ['name' => 'Clinic Admin', 'email' => '', 'role_id' => $role['receptionist']]);
t('last active administrator cannot be demoted', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'last active administrator'));

// ---------------------------------------------------------------- D. sign-in & session security
section('D. Sign-in and session security');
$anon = new Client();
foreach (['/admin', '/admin/users', '/reception', '/marketing', '/', '/admin/audit'] as $p) {
    $r = $anon->req('GET', $p);
    t("anonymous $p -> login", $r['status'] === 302 && $r['loc'] === '/login');
}
$r = $anon->req('GET', '/login');
t('login page renders with the official logo', $r['status'] === 200 && str_contains($r['body'], '/assets/images/babi-logo.jpg'));
t('logo file served unmodified', md5_file(dirname(__DIR__) . '/public/assets/images/babi-logo.jpg') === md5_file('/mnt/user-data/uploads/1000096268.jpg'));
t('CSP header', str_contains($r['headers'], "Content-Security-Policy: default-src 'self'"));
t('X-Frame-Options + nosniff + no-store', str_contains($r['headers'], 'X-Frame-Options: DENY') && str_contains($r['headers'], 'nosniff') && str_contains($r['headers'], 'no-store'));
$fresh = (new Client())->req('GET', '/login');
t('session cookie HttpOnly + SameSite=Strict', (bool) preg_match('/Set-Cookie: babi_sid=[^;]+;.*HttpOnly/i', $fresh['headers']) && stripos($fresh['headers'], 'SameSite=Strict') !== false);
t('login POST without CSRF token rejected', $anon->req('POST', '/login', ['username' => 'boss', 'password' => $ADMIN_PW])['status'] === 403);
$other = new Client(); $tokB = $other->csrf('/login'); $tokA = $anon->csrf('/login');
t("CSRF token from another session rejected", $anon->req('POST', '/login', ['username' => 'boss', 'password' => $ADMIN_PW, '_csrf' => $tokB])['status'] === 403);

$bad = $anon->login('boss', 'wrong-password-123');
$unk = $anon->login('nosuchuser', 'wrong-password-123');
t('wrong password -> 401 generic message', $bad['status'] === 401 && str_contains($bad['body'], 'Incorrect username or password.'));
t('unknown user gives identical response (no enumeration)', $unk['status'] === 401 && str_contains($unk['body'], 'Incorrect username or password.'));
t('SQL injection in username fails safely', $anon->login("' OR 1=1 -- ", 'x')['status'] === 401 && $anon->login("boss' --", 'x')['status'] === 401);

$fx = new Client(); $fixed = str_repeat('a', 48); $fx->setCookie($fixed);
$fx->login('boss', $ADMIN_PW);
t('session fixation: attacker-chosen id replaced at login', $fx->cookie() !== null && $fx->cookie() !== $fixed);

$A = new Client(); $pre = ''; $A->req('GET', '/login'); $pre = (string) $A->cookie();
$r = $A->login('boss', $ADMIN_PW);
t('valid login redirects to /', $r['status'] === 302 && $r['loc'] === '/');
t('session id regenerated on login', $A->cookie() !== $pre && $A->cookie() !== null);
$r = $A->req('GET', '/admin');
t('admin dashboard loads', $r['status'] === 200 && str_contains($r['body'], 'Administrator dashboard'));
t('guest-only /login redirects when signed in', $A->req('GET', '/login')['status'] === 302);

$oldSid = (string) $A->cookie();
t('logout without CSRF rejected (still signed in)', $A->req('POST', '/logout')['status'] === 403 && $A->req('GET', '/admin')['status'] === 200);
t('GET /logout not allowed', $A->req('GET', '/logout')['status'] === 405);
$r = $A->post('/admin', '/logout', []);
t('logout redirects to login', $r['status'] === 302 && $r['loc'] === '/login');
t('after logout /admin needs login', $A->req('GET', '/admin')['status'] === 302);
$replay = new Client(); $replay->setCookie($oldSid);
t('old session cookie cannot be replayed after logout', $replay->req('GET', '/admin')['status'] === 302);

// idle / absolute timeout
$A = new Client(); $A->login('boss', $ADMIN_PW);
$f = "/tmp/babi_sessions_test/sess_" . $A->cookie();
file_put_contents($f, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 3600) . ';', (string) file_get_contents($f)));
$r = $A->req('GET', '/admin');
t('idle timeout (30 min) ends the session', $r['status'] === 302 && $r['loc'] === '/login' && str_contains($A->req('GET', '/login')['body'], 'session expired'));
$A = new Client(); $A->login('boss', $ADMIN_PW);
$f = "/tmp/babi_sessions_test/sess_" . $A->cookie();
file_put_contents($f, preg_replace('/created_at\|i:\d+;/', 'created_at|i:' . (time() - 9 * 3600) . ';', (string) file_get_contents($f)));
t('absolute lifetime (8 h) ends the session even if active', $A->req('GET', '/admin')['status'] === 302);

// ---------------------------------------------------------------- E. account management
section('E. Account management');
$A = new Client(); $A->login('boss', $ADMIN_PW);
$mk = fn(string $n, string $u, string $r, string $pw = '') => adminForm($A, ['name' => $n, 'username' => $u, 'email' => "$u@example.test", 'role_id' => $role[$r], 'password' => $pw]);
$r = $mk('Rita Reception', 'rec1', 'receptionist', $TEMP);
t('admin creates receptionist', $r['status'] === 200 && str_contains($r['body'], 'was created'));
$mk('Cal Operator', 'call1', 'call_operator', $TEMP); $mk('Sam Social', 'soc1', 'social_tracker', $TEMP); $mk('Mia Marketing', 'mkt1', 'marketing', $TEMP);
t('four staff accounts exist', (int) $db->query("SELECT COUNT(*) FROM users WHERE username IN ('rec1','call1','soc1','mkt1')")->fetchColumn() === 4);
$r = adminForm($A, ['name' => 'Gen Erated', 'username' => 'gen1', 'email' => '', 'role_id' => $role['receptionist'], 'password' => '']);
preg_match('/Temporary password<\/dt><dd class="col-sm-8"><code>([^<]+)<\/code>/', $r['body'], $m); $gen = $m[1] ?? '';
t('blank password generates a policy-compliant temporary password shown once', strlen($gen) === 16 && PP::validate($gen, 'gen1') === []);
$G = new Client(); $G->login('gen1', $gen);
t('generated password works and forces a change', $G->req('GET', '/reception')['loc'] === '/password/change');
t('duplicate username rejected', adminForm($A, ['name' => 'Dup', 'username' => 'rec1', 'email' => '', 'role_id' => $role['receptionist'], 'password' => $TEMP])['status'] === 422);
t('weak password rejected on create', adminForm($A, ['name' => 'Weak', 'username' => 'weak1', 'email' => '', 'role_id' => $role['receptionist'], 'password' => 'abc'])['status'] === 422);
t('invalid username rejected', adminForm($A, ['name' => 'Bad', 'username' => 'Bad Name!', 'email' => '', 'role_id' => $role['receptionist'], 'password' => $TEMP])['status'] === 422);
t('invalid role rejected', adminForm($A, ['name' => 'Bad', 'username' => 'badrole', 'email' => '', 'role_id' => 999, 'password' => $TEMP])['status'] === 422);
adminForm($A, ['name' => '<script>alert(1)</script>', 'username' => 'xss1', 'email' => '', 'role_id' => $role['marketing'], 'password' => $TEMP]);
$list = $A->req('GET', '/admin/users')['body'];
t('stored XSS payload is escaped in user list', str_contains($list, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($list, '<script>alert(1)'));
$hs = $db->query("SELECT password_hash FROM users WHERE username='rec1'")->fetchColumn();
t('staff password stored hashed', str_starts_with((string) $hs, '$2y$') && $hs !== $TEMP);

$R = new Client(); $r = $R->login('rec1', $TEMP);
t('first login -> dashboard route', $r['status'] === 302);
t('forced change: / redirects to /password/change', $R->req('GET', '/')['loc'] === '/password/change');
t('forced change: /reception also blocked', $R->req('GET', '/reception')['loc'] === '/password/change');
$cp = fn(Client $c, string $cur, string $new, string $conf) => $c->post('/password/change', '/password/change', ['current_password' => $cur, 'new_password' => $new, 'confirm_password' => $conf]);
t('change: wrong current password rejected', cp0($R, 'nope-nope-1234', $FINAL, $FINAL));
function cp0(Client $c, string $cur, string $new, string $conf): bool { return $c->post('/password/change', '/password/change', ['current_password' => $cur, 'new_password' => $new, 'confirm_password' => $conf])['status'] === 422; }
t('change: weak new password rejected', cp0($R, $TEMP, 'short1', 'short1'));
t('change: mismatch rejected', cp0($R, $TEMP, $FINAL, $FINAL . 'x'));
t('change: same as current rejected', cp0($R, $TEMP, $TEMP, $TEMP));
$r = $cp($R, $TEMP, $FINAL, $FINAL);
t('change: success redirects', $r['status'] === 302);
t('after change receptionist reaches /reception', $R->req('GET', '/reception')['status'] === 200);
t('old temp password no longer works', (new Client())->login('rec1', $TEMP)['status'] === 401);
foreach (['call1', 'soc1', 'mkt1'] as $u) { $c = new Client(); $c->login($u, $TEMP); $cp($c, $TEMP, $FINAL, $FINAL); }
// self-protection
$r = $A->post('/admin/users', "/admin/users/$adminId/active", []);
t('admin cannot deactivate self', (int) $db->query("SELECT is_active FROM users WHERE id=$adminId")->fetchColumn() === 1);
$r = $A->post("/admin/users/$adminId/edit", "/admin/users/$adminId", ['name' => 'Clinic Admin', 'email' => '', 'role_id' => $role['receptionist']]);
t('admin cannot change own role', $r['status'] === 422 && (int) $db->query("SELECT role_id FROM users WHERE id=$adminId")->fetchColumn() === $role['admin']);

// role change / deactivate invalidate live sessions
$mkU = (int) $db->query("SELECT id FROM users WHERE username='mkt1'")->fetchColumn();
$M = new Client(); $M->login('mkt1', $FINAL);
t('marketing user reaches /marketing', $M->req('GET', '/marketing')['status'] === 200);
$A->post("/admin/users/$mkU/edit", "/admin/users/$mkU", ['name' => 'Mia Marketing', 'email' => 'mkt1@example.test', 'role_id' => $role['receptionist']]);
t('role change ends the user\'s live session immediately', $M->req('GET', '/marketing')['loc'] === '/login');
$M->login('mkt1', $FINAL);
t('new role permissions apply after re-login (reception ok, marketing denied)', $M->req('GET', '/reception')['status'] === 200 && $M->req('GET', '/marketing')['status'] === 403);
t('role change audited with old/new role', $audit('user.role_change') === 1 && str_contains((string) $db->query("SELECT new_values FROM audit_logs WHERE action='user.role_change'")->fetchColumn(), 'receptionist'));
$A->post('/admin/users', "/admin/users/$mkU/active", []);
t('deactivation ends live session', $M->req('GET', '/reception')['loc'] === '/login');
t('deactivated account cannot sign in (generic error)', str_contains((new Client())->login('mkt1', $FINAL)['body'], 'Incorrect username or password.'));
$A->post('/admin/users', "/admin/users/$mkU/active", []);
t('reactivated account can sign in', (new Client())->login('mkt1', $FINAL)['status'] === 302);

// password reset flow
$T = new Client(); $T->login('soc1', $FINAL);
$soc = (int) $db->query("SELECT id FROM users WHERE username='soc1'")->fetchColumn();
$r = $A->post('/admin/users', "/admin/users/$soc/reset", []);
preg_match('#/reset-password\?token=([a-f0-9]{64})#', $r['body'], $m); $tok = $m[1] ?? '';
t('admin issues a reset link', strlen($tok) === 64);
t('only the token hash is stored', (int) $db->query("SELECT COUNT(*) FROM password_resets WHERE token_hash=" . $db->quote($tok))->fetchColumn() === 0 && (int) $db->query("SELECT COUNT(*) FROM password_resets WHERE token_hash=" . $db->quote(hash('sha256', $tok)))->fetchColumn() === 1);
t('issuing a reset ends the user\'s live sessions', $T->req('GET', '/social-media')['loc'] === '/login');
$P = new Client(); $r = $P->req('GET', "/reset-password?token=$tok");
t('valid token shows reset form', $r['status'] === 200 && str_contains($r['body'], 'Set a new password'));
$fp = fn(string $new, string $conf, string $token) => $P->post("/reset-password?token=$tok", '/reset-password', ['token' => $token, 'new_password' => $new, 'confirm_password' => $conf]);
t('reset: weak password rejected', $fp('short1', 'short1', $tok)['status'] === 422);
t('reset: mismatch rejected', $fp($FINAL . 'a', $FINAL . 'b', $tok)['status'] === 422);
$NEWPW = 'Meadow-Violet-6420';
t('reset: success redirects to login', $fp($NEWPW, $NEWPW, $tok)['status'] === 302);
t('new password works, old does not', (new Client())->login('soc1', $NEWPW)['status'] === 302 && (new Client())->login('soc1', $FINAL)['status'] === 401);
t('reset link is single-use (GET)', $P->req('GET', "/reset-password?token=$tok")['status'] === 400);
$rr = $P->req('POST', '/reset-password', ['_csrf' => $P->csrf('/login'), 'token' => $tok, 'new_password' => $NEWPW . 'z', 'confirm_password' => $NEWPW . 'z']);
t('reset link is single-use (POST) and password unchanged', $rr['status'] === 422 && (new Client())->login('soc1', $NEWPW)['status'] === 302);
$r = $A->post('/admin/users', "/admin/users/$soc/reset", []); preg_match('#token=([a-f0-9]{64})#', $r['body'], $m); $tok2 = $m[1];
$r = $A->post('/admin/users', "/admin/users/$soc/reset", []); preg_match('#token=([a-f0-9]{64})#', $r['body'], $m); $tok3 = $m[1];
t('issuing a new link voids the previous one', $P->req('GET', "/reset-password?token=$tok2")['status'] === 400 && $P->req('GET', "/reset-password?token=$tok3")['status'] === 200);
$db->exec("UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash=" . $db->quote(hash('sha256', $tok3)));
t('expired reset link rejected', $P->req('GET', "/reset-password?token=$tok3")['status'] === 400);
t('malformed token rejected', $P->req('GET', '/reset-password?token=abc')['status'] === 400);

// own password change kills other sessions
$S1 = new Client(); $S2 = new Client(); $S1->login('soc1', $NEWPW); $S2->login('soc1', $NEWPW);
$PW3 = 'Orchard-Copper-8153'; $cp($S1, $NEWPW, $PW3, $PW3);
t('changing password signs out other sessions, keeps current one', $S2->req('GET', '/social-media')['loc'] === '/login' && $S1->req('GET', '/social-media')['status'] === 200);

// ---------------------------------------------------------------- F. authorization matrix
section('F. Server-side authorization matrix');
$db->exec('DELETE FROM login_attempts');
$sess = ['admin' => ['boss', $ADMIN_PW], 'receptionist' => ['rec1', $FINAL], 'call_operator' => ['call1', $FINAL], 'social_tracker' => ['soc1', $PW3], 'marketing' => ['xss1', null]];
// xss1 is a marketing user with TEMP password: complete its forced change first
$X = new Client(); $X->login('xss1', $TEMP); $cp($X, $TEMP, $FINAL, $FINAL); $sess['marketing'] = ['xss1', $FINAL];
$clients = [];
foreach ($sess as $roleCode => [$u, $p]) { $c = new Client(); $c->login($u, $p); $clients[$roleCode] = $c; }
$matrix = [
    '/admin'          => ['admin' => 200, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 403],
    '/admin/users'    => ['admin' => 200, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 403],
    '/admin/users/new'=> ['admin' => 200, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 403],
    "/admin/users/$adminId/edit" => ['admin' => 200, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 403],
    '/admin/audit'    => ['admin' => 200, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 403],
    '/reception'      => ['admin' => 403, 'receptionist' => 200, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 403],
    '/call-operator'  => ['admin' => 403, 'receptionist' => 403, 'call_operator' => 200, 'social_tracker' => 403, 'marketing' => 403],
    '/social-media'   => ['admin' => 403, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 200, 'marketing' => 403],
    '/marketing'      => ['admin' => 200, 'receptionist' => 403, 'call_operator' => 403, 'social_tracker' => 403, 'marketing' => 200],
];
foreach ($matrix as $path => $exp) {
    foreach ($exp as $roleCode => $status) {
        $got = $clients[$roleCode]->req('GET', $path)['status'];
        t("$roleCode GET $path -> $status", $got === $status, "got $got");
    }
}
foreach (['admin' => '/admin', 'receptionist' => '/reception', 'call_operator' => '/call-operator', 'social_tracker' => '/social-media', 'marketing' => '/marketing'] as $roleCode => $home) {
    t("$roleCode lands on own dashboard", $clients[$roleCode]->req('GET', '/')['loc'] === $home);
}
$before = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
foreach (['receptionist', 'call_operator', 'social_tracker', 'marketing'] as $roleCode) {
    $c = $clients[$roleCode];
    $tok = $c->csrf(['receptionist' => '/reception', 'call_operator' => '/call-operator', 'social_tracker' => '/social-media', 'marketing' => '/marketing'][$roleCode]);
    $r = $c->req('POST', '/admin/users', ['_csrf' => $tok, 'name' => 'Hacker', 'username' => 'hacker_' . $roleCode, 'role_id' => $role['admin'], 'password' => $TEMP]);
    t("$roleCode cannot create users even with a valid CSRF token", $r['status'] === 403);
}
t('no user was created by unauthorised POSTs', (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before);
$rc = $clients['receptionist']; $tokR = $rc->csrf('/reception');
t('receptionist cannot deactivate admin (IDOR attempt)', $rc->req('POST', "/admin/users/$adminId/active", ['_csrf' => $tokR])['status'] === 403 && (int) $db->query("SELECT is_active FROM users WHERE id=$adminId")->fetchColumn() === 1);
t('receptionist cannot issue reset links', $rc->req('POST', "/admin/users/$adminId/reset", ['_csrf' => $tokR])['status'] === 403);
t('admin POST without CSRF rejected', $clients['admin']->req('POST', "/admin/users/$mkU/active")['status'] === 403);
t('access denials are audited', $audit('auth.access_denied') >= 40);
t('unknown route -> 404', $clients['admin']->req('GET', '/does-not-exist')['status'] === 404);
t('non-numeric id -> 404', $clients['admin']->req('GET', '/admin/users/abc/edit')['status'] === 404);
t('missing user id -> 404', $clients['admin']->req('GET', '/admin/users/99999/edit')['status'] === 404);
foreach (['/.env', '/app/bootstrap.php', '/config/routes.php', '/storage/logs/app.log', '/database/schema.sql'] as $p) {
    t("$p is not web-accessible", $anon->req('GET', $p)['status'] === 404);
}

// ---------------------------------------------------------------- G. throttling
section('G. Rate limiting');
$db->exec('DELETE FROM login_attempts');
$L = new Client();
for ($i = 0; $i < 5; $i++) { $L->login('call1', 'wrong-guess-' . $i . 'aB'); }
$r = $L->login('call1', $FINAL);
t('account locked after 5 failures, even the correct password is refused (429)', $r['status'] === 429 && str_contains($r['body'], 'Too many failed attempts'));
t('lockout is audited', $audit('auth.login_blocked') >= 1);
t('lockout does not reveal whether a username exists', (function () { $c = new Client(); for ($i = 0; $i < 5; $i++) { $c->login('ghost_user', 'x' . $i); } return str_contains($c->login('ghost_user', 'x')['body'], 'Too many failed attempts'); })());
$db->exec("UPDATE login_attempts SET attempted_at = NOW() - INTERVAL 20 MINUTE");
t('lockout expires after the window', (new Client())->login('call1', $FINAL)['status'] === 302);
$db->exec('DELETE FROM login_attempts');
$I = new Client();
for ($i = 0; $i < 20; $i++) { $I->login('spray' . $i, 'x'); }
t('IP-wide throttle blocks password spraying across usernames', (new Client())->login('call1', $FINAL)['status'] === 429);
$db->exec('DELETE FROM login_attempts');
t('success clears the failure streak', (function () use ($FINAL) { $c = new Client(); for ($i = 0; $i < 3; $i++) { $c->login('rec1', 'bad' . $i . 'xY'); } $c->login('rec1', $FINAL); $d = new Client(); for ($i = 0; $i < 3; $i++) { $d->login('rec1', 'bad' . $i . 'xY'); } return $d->login('rec1', $FINAL)['status'] === 302; })());
$db->exec('DELETE FROM login_attempts');
$P2 = new Client(); for ($i = 0; $i < 21; $i++) { $P2->req('GET', '/reset-password?token=' . str_repeat('0', 64)); }
t('reset-token guessing is throttled', $P2->req('GET', '/reset-password?token=' . str_repeat('0', 64))['status'] === 429);
$db->exec('DELETE FROM login_attempts');

// ---------------------------------------------------------------- H. deployment-mode behaviour
section('H. Production mode and error handling');
$prod = new Client('http://127.0.0.1:8098');
$r = $prod->req('GET', '/login');
t('production: plain HTTP redirected (301) to the configured https URL', $r['status'] === 301 && str_starts_with($r['loc'], 'https://babi.example.test/'));
$r = $prod->req('GET', '/login', null, ['X-Forwarded-Proto: https']);
t('production over HTTPS: page served with HSTS', $r['status'] === 200 && str_contains($r['headers'], 'Strict-Transport-Security'));
t('production cookie: Secure, HttpOnly, SameSite=Strict, __Host- prefix', (bool) preg_match('/Set-Cookie: __Host-babi_sid=[^;]+;.*Secure/i', $r['headers']) && stripos($r['headers'], 'HttpOnly') !== false && stripos($r['headers'], 'SameSite=Strict') !== false);
$bad = new Client('http://127.0.0.1:8097'); $tk = $bad->csrf('/login');
$r = $bad->req('POST', '/login', ['_csrf' => $tk, 'username' => 'boss', 'password' => 'x']);
t('database failure -> generic 500, no SQL/credential details leaked', $r['status'] === 500 && !preg_match('/SQLSTATE|Access denied|babi_app|wrong-password|PDO/i', $r['body']));
$log = (string) @file_get_contents(dirname(__DIR__) . '/storage/logs/app.log');
t('error details written to server log only, without the password', str_contains($log, 'PDOException') && !str_contains($log, 'wrong-password'));

// ---------------------------------------------------------------- I. audit trail
section('I. Audit trail');
foreach (['auth.login_success', 'auth.login_failed', 'auth.logout', 'auth.login_blocked', 'auth.password_changed', 'auth.password_reset_completed', 'auth.access_denied', 'user.create', 'user.role_change', 'user.deactivate', 'user.activate', 'user.password_reset_issued', 'user.bootstrap_admin'] as $a) {
    t("audit event recorded: $a", $audit($a) >= 1);
}
$all = (string) $db->query("SELECT GROUP_CONCAT(COALESCE(old_values,''), COALESCE(new_values,'')) FROM audit_logs")->fetchColumn();
t('no password or token appears in any audit payload', !str_contains($all, $TEMP) && !str_contains($all, $FINAL) && !str_contains($all, $ADMIN_PW) && !str_contains($all, $gen) && !preg_match('/[a-f0-9]{64}/', $all));
$a = $clients['admin']->req('GET', '/admin/audit?action=user.');
t('audit page renders and filters', $a['status'] === 200 && str_contains($a['body'], 'user.create') && !str_contains($a['body'], 'auth.login_success'));
$st = $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.create' AND user_id=$adminId")->fetchColumn();
t('audit rows attribute the acting administrator', (int) $st >= 6);

finish('Phase 1+2 regression suite');
