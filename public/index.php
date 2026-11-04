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
    // Temporary troubleshooting aid: set APP_DEBUG=1 in the hosting environment to see the real error in the browser.
    // Leave it unset (or 0) in normal use, because error text can reveal server details.
    if (Env::get('APP_DEBUG') === '1') {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "DEBUG MODE (remove APP_DEBUG when done)\n" . $entry . "\n";
        exit;
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
