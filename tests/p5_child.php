<?php
declare(strict_types=1);
// Child process for tests/phase5.php: runs under whatever APP_TIMEZONE the parent set. Prints one JSON line.
putenv('APP_ENV=testing');
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\{Clock, Db};
use App\Services\InteractionService;

$out = ['tz' => date_default_timezone_get(), 'offset' => Clock::mysqlOffset()];
$pdo = Db::pdo();
$out['session_tz'] = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
$out['skew'] = Clock::now()->getTimestamp() - (new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn(), Clock::zone()))->getTimestamp();
$ids = array_filter(explode(',', (string) ($argv[1] ?? '')));
foreach ($ids as $id) {
    $st = $pdo->prepare('SELECT * FROM interactions WHERE id = ?');
    $st->execute([(int) $id]);
    $i = $st->fetch();
    $out['edit'][$id] = InteractionService::canEdit(['id' => (int) $i['recorded_by_user_id'], 'role_code' => 'receptionist'], $i)['allowed'];
}
echo json_encode($out), PHP_EOL;
