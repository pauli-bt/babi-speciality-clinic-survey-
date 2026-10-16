<?php
declare(strict_types=1);
// Read-only timezone diagnostic. Never writes to the database.  Usage: php bin/check-timezone.php
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\{Clock, Db};

$problems = 0; $warn = 0;
$say = static fn(string $l, string $m) => print(str_pad($l, 6) . $m . PHP_EOL);

try {
    $name = Clock::configuredName();
} catch (RuntimeException $e) {
    $say('FAIL', $e->getMessage());
    exit(1);
}
$say('OK', "APP_TIMEZONE = $name (PHP default zone " . date_default_timezone_get() . ', offset ' . Clock::mysqlOffset() . ')');
if ($name !== Clock::DEFAULT_TZ) { $say('NOTE', 'Not the clinic default ' . Clock::DEFAULT_TZ . '; confirm this is intended.'); }

try {
    $pdo = Db::pdo();
} catch (Throwable $e) {
    $say('FAIL', 'Cannot connect to the database: ' . $e->getMessage());
    exit(1);
}
$sessionTz = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
if ($sessionTz === Clock::mysqlOffset()) { $say('OK', "MySQL session time_zone = $sessionTz"); }
else { $say('FAIL', "MySQL session time_zone is $sessionTz, expected " . Clock::mysqlOffset()); $problems++; }

$row = $pdo->query('SELECT NOW() AS n, UNIX_TIMESTAMP() AS u')->fetch();
$phpNow = Clock::now();
$dbNow = new DateTimeImmutable($row['n'], Clock::zone());
$skew = $phpNow->getTimestamp() - $dbNow->getTimestamp();
if (abs($skew) <= 5) { $say('OK', "PHP/MySQL clock skew {$skew}s"); }
else { $say('FAIL', "PHP and MySQL disagree by {$skew}s (timezone or clock problem)"); $problems++; }

// Historical rows: created_at stamped in the future suggests data written under a different timezone.
$tables = ['users', 'customers', 'interactions', 'appointments', 'audit_logs'];
foreach ($tables as $t) {
    try {
        $st = $pdo->query("SELECT COUNT(*) c, MAX(created_at) m FROM `$t` WHERE created_at > NOW() + INTERVAL 5 MINUTE");
        $r = $st->fetch();
        if ((int) $r['c'] > 0) { $say('WARN', "$t: {$r['c']} row(s) with created_at in the future (latest {$r['m']}); investigate manually, do not shift blindly."); $warn++; }
        else { $say('OK', "$t: no created_at values in the future"); }
    } catch (PDOException $e) {
        $say('SKIP', "$t: " . $e->getMessage());
    }
}
$say($problems ? 'FAIL' : 'DONE', "problems=$problems warnings=$warn (read-only: nothing was changed)");
exit($problems ? 1 : 0);
