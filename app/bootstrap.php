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
try {
    date_default_timezone_set(App\Helpers\Clock::configuredName());
} catch (RuntimeException $e) {
    http_response_code(500);
    error_log('[babi] ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    exit('Server configuration error.');
}
