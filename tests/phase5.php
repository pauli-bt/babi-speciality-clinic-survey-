<?php
declare(strict_types=1);
// Phase 5 suite: timezone handling. Every timezone runs in its OWN child process (own env, own DB connection),
// fixtures are created and removed per run inside try/finally, so configurations cannot contaminate each other.
require __DIR__ . '/lib.php';

use App\Helpers\Clock;

$db = admin_pdo('babi_test');
$PW_ENV = (string) getenv('PW');

/** Run a child PHP with the given environment overrides; returns [exitCode, stdout, stderr]. */
function child(string $script, array $args, array $env): array {
    global $PW_ENV;
    $base = ['DB_HOST' => 'localhost', 'DB_USER' => 'babi_app', 'DB_PASS' => $PW_ENV, 'DB_NAME' => 'babi_test', 'APP_ENV' => 'testing', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $p = proc_open(array_merge([PHP_BINARY, $script], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..', array_merge($base, $env));
    $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $o, $e];
}

section('A. Clock and offsets (pure, per timezone)');
$cases = ['UTC' => '+00:00', 'Africa/Addis_Ababa' => '+03:00', 'America/New_York' => null, 'Pacific/Auckland' => null, 'Asia/Kolkata' => '+05:30'];
foreach ($cases as $tz => $fixed) {
    [$rc, $o, $e] = child(__DIR__ . '/p5_child.php', [], ['APP_TIMEZONE' => $tz]);
    $j = json_decode(trim($o), true);
    t("$tz: child boots (exit 0)", $rc === 0 && is_array($j), "rc=$rc $e");
    if (!is_array($j)) continue;
    t("$tz: PHP default timezone = APP_TIMEZONE", $j['tz'] === $tz);
    t("$tz: MySQL session time_zone equals the PHP offset ({$j['offset']})", $j['session_tz'] === $j['offset'], "session={$j['session_tz']}");
    if ($fixed) t("$tz: fixed expected offset $fixed", $j['offset'] === $fixed);
    t("$tz: PHP/MySQL clock skew within 5s", abs((int) $j['skew']) <= 5, 'skew=' . $j['skew']);
}

section('B. Default timezone and DST behaviour');
[$rc, $o] = child(__DIR__ . '/p5_child.php', [], ['APP_TIMEZONE' => '']);
$j = json_decode(trim($o), true);
t('empty APP_TIMEZONE falls back to Africa/Addis_Ababa (+03:00)', is_array($j) && $j['tz'] === 'Africa/Addis_Ababa' && $j['offset'] === '+03:00');
$ny = new DateTimeZone('America/New_York'); $addis = new DateTimeZone('Africa/Addis_Ababa');
$off = fn(DateTimeZone $z, string $d) => $z->getOffset(new DateTimeImmutable($d, new DateTimeZone('UTC')));
t('Addis Ababa has no DST (Jan and Jul both +3h)', $off($addis, '2026-01-15') === 10800 && $off($addis, '2026-07-15') === 10800);
t('New York is -5h in January and -4h in July (DST)', $off($ny, '2026-01-15') === -18000 && $off($ny, '2026-07-15') === -14400);

section('C. Local date boundary (21:30 UTC is already tomorrow in Addis Ababa)');
$instant = new DateTimeImmutable('2026-03-01 21:30:00', new DateTimeZone('UTC'));
t('Addis local date = 2026-03-02', $instant->setTimezone($addis)->format('Y-m-d') === '2026-03-02');
t('UTC date = 2026-03-01', $instant->format('Y-m-d') === '2026-03-01');
t('New York local date = 2026-03-01 (negative offset)', $instant->setTimezone($ny)->format('Y-m-d') === '2026-03-01');

section('D. Invalid configuration fails clearly');
foreach (['Mars/Olympus', '+03:00', 'EAT', 'not a zone'] as $bad) {
    [$rc, $o, $e] = child(__DIR__ . '/p5_child.php', [], ['APP_TIMEZONE' => $bad]);
    t("APP_TIMEZONE=\"$bad\" is rejected (non-zero exit, clear message, no DB work)", $rc !== 0 && str_contains($o . $e, 'Invalid APP_TIMEZONE') && !str_contains($o, '"session_tz"'), "rc=$rc");
}
[$rc, $o, $e] = child(__DIR__ . '/../bin/check-timezone.php', [], ['APP_TIMEZONE' => 'Mars/Olympus']);
t('check-timezone.php exits 1 with a clear message on an invalid zone', $rc === 1 && str_contains($o . $e, 'Invalid APP_TIMEZONE'));

section('E. 24-hour edit window is elapsed time, in every timezone');
$roleId = (int) $db->query("SELECT id FROM roles WHERE code='receptionist'")->fetchColumn();
$srcId = (int) $db->query('SELECT id FROM acquisition_sources LIMIT 1')->fetchColumn();
foreach (['UTC', 'Africa/Addis_Ababa', 'America/New_York', 'Pacific/Auckland'] as $tz) {
    $uid = $cid = null; $made = [];
    try {
        $zone = new DateTimeZone($tz);
        $db->exec("SET time_zone = '" . (new DateTimeImmutable('now', $zone))->format('P') . "'");
        $u = 'p5_' . bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO users (name, username, password_hash, role_id, must_change_password) VALUES (?,?,?,?,0)')->execute(['P5 User', $u, 'x', $roleId]);
        $uid = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO customers (customer_code,name,original_source_id,first_acquisition_channel,first_acquisition_at,created_by_user_id) VALUES (?,?,?,'reception',NOW(),?)")
            ->execute(['BDC-9' . random_int(10000, 99999), 'P5 Customer', $srcId, $uid]);
        $cid = (int) $db->lastInsertId();
        $ins = $db->prepare("INSERT INTO interactions (customer_id,channel,interaction_reason,is_first_contact,interaction_at,recorded_by_user_id,created_at) VALUES (?,'reception','first_contact',1,?,?,?)");
        foreach (['recent' => 5, 'in' => 23 * 60 + 58, 'out' => 24 * 60 + 2] as $k => $mins) {
            $c = (new DateTimeImmutable('now', $zone))->modify("-$mins minutes")->format('Y-m-d H:i:s');
            $ins->execute([$cid, $c, $uid, $c]);
            $made[$k] = (int) $db->lastInsertId();
        }
        [$rc, $o, $e] = child(__DIR__ . '/p5_child.php', [implode(',', $made)], ['APP_TIMEZONE' => $tz]);
        $j = json_decode(trim($o), true);
        t("$tz: child ran", $rc === 0 && is_array($j), "rc=$rc $e");
        if (is_array($j)) {
            t("$tz: 5 minutes old -> editable", $j['edit'][$made['recent']] === true);
            t("$tz: 23h58m old -> editable", $j['edit'][$made['in']] === true);
            t("$tz: 24h02m old -> locked", $j['edit'][$made['out']] === false);
        }
    } finally {
        if ($cid) { $db->exec("DELETE FROM interactions WHERE customer_id = $cid"); $db->exec("DELETE FROM customers WHERE id = $cid"); }
        if ($uid) { $db->exec("DELETE FROM users WHERE id = $uid"); }
    }
}
$left = (int) $db->query("SELECT COUNT(*) FROM users WHERE username LIKE 'p5\\_%'")->fetchColumn();
t('fixture cleanup left nothing behind', $left === 0);

section('F. Read-only diagnostic');
[$rc, $o] = child(__DIR__ . '/../bin/check-timezone.php', [], ['APP_TIMEZONE' => 'Africa/Addis_Ababa']);
t('check-timezone.php exits 0 and reports session time_zone +03:00', $rc === 0 && str_contains($o, 'session time_zone = +03:00'), $o);
t('check-timezone.php reports no problems', str_contains($o, 'problems=0'));
[$rc, $o] = child(__DIR__ . '/../bin/check-timezone.php', [], ['APP_TIMEZONE' => 'America/New_York']);
t('check-timezone.php works for a negative offset', $rc === 0 && preg_match('/session time_zone = -0[45]:00/', $o) === 1);

finish('Phase 5 suite');
