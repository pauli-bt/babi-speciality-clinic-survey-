<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Services\Audit;
use App\Services\Auth;

/**
 * Route options:
 *   auth (bool)         require a valid session
 *   guest (bool)        only for signed-out visitors
 *   perm (string)       required permission code (checked on the server for every request)
 *   role (string)       required role code (binds entry channels to their roles)
 *   allow_forced (bool) reachable while a forced password change is pending
 * Every POST is CSRF-checked.
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d{1,10})', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'opts' => $opts];
    }

    public function dispatch(string $method, string $path): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $this->run($route, $method, $path, array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)));
            return;
        }
        Http::abort($pathMatched ? 405 : 404);
    }

    private function run(array $route, string $method, string $path, array $params): void
    {
        $o = $route['opts'];
        $user = Auth::user();

        if (!empty($o['guest']) && $user) {
            Http::redirect('/');
        }
        if (!empty($o['auth'])) {
            if (!$user) {
                Http::redirect('/login');
            }
            if ((int) $user['must_change_password'] === 1 && empty($o['allow_forced'])) {
                Http::redirect('/password/change');
            }
            if ((isset($o['role']) && $user['role_code'] !== $o['role']) || (isset($o['perm']) && !Auth::can($o['perm']))) {
                Audit::log((int) $user['id'], 'auth.access_denied', 'route', null, null, ['method' => $method, 'path' => $path]);
                Http::abort(403);
            }
        }
        if ($method === 'POST' && !Csrf::valid()) {
            Http::abort(403, 'Your session expired or the request was invalid. Go back, reload the page and try again.');
        }
        [$class, $action] = $route['handler'];
        (new $class())->$action(...array_values($params));
    }
}
