<?php
declare(strict_types=1);
// Child for tests/phase6.php: changes an appointment's status under the APP_TIMEZONE given in the environment.
putenv('APP_ENV=testing');
require __DIR__ . '/../app/bootstrap.php';
$res = App\Services\AppointmentManager::changeStatus(['id' => (int) $argv[3]], (int) $argv[1], $argv[2]);
echo json_encode(['tz' => date_default_timezone_get()] + $res), PHP_EOL;
