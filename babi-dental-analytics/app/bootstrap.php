<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Helpers/functions.php';
App\Helpers\Env::load(BASE_PATH . '/.env');
date_default_timezone_set(App\Helpers\Env::get('APP_TIMEZONE', 'Africa/Addis_Ababa'));
