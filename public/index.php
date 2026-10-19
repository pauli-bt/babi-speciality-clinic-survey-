<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\{Env, Http, Session, View};

set_exception_handler(static function (Throwable $t): void {
    // Details go to the log only, never to the browser.
    $dir = BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $entry = sprintf("[%s] %s: %s in %s:%d", date('c'), get_class($t), $t->getMessage(), basename($t->getFile()), $t->getLine());
    @file_put_contents($dir . '/app.log', $entry . "\n", FILE_APPEND | LOCK_EX);
    // Render captures PHP error_log output, while the browser still receives only a generic message.
    error_log('[babi] ' . $entry);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Something went wrong. Please try again or contact the administrator.';
    exit;
});

Http::hardenRuntime();
Http::enforceHttps();
Session::start();
Http::securityHeaders();

/** @var App\Helpers\Router $router */
$router = require BASE_PATH . '/config/routes.php';
$router->dispatch(Http::method(), Http::path());
