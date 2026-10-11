<?php
declare(strict_types=1);

use App\Controllers\{AuditController, AuthController, CustomerController, DashboardController, InteractionController, UserController};
use App\Helpers\Router;

$r = new Router();

// Public
$r->add('GET', '/login', [AuthController::class, 'showLogin'], ['guest' => true]);
$r->add('POST', '/login', [AuthController::class, 'login'], ['guest' => true]);
$r->add('GET', '/reset-password', [AuthController::class, 'showReset']);
$r->add('POST', '/reset-password', [AuthController::class, 'reset']);

// Any signed-in user
$r->add('GET', '/', [DashboardController::class, 'home'], ['auth' => true]);
$r->add('POST', '/logout', [AuthController::class, 'logout'], ['auth' => true, 'allow_forced' => true]);
$r->add('GET', '/password/change', [AuthController::class, 'showChangePassword'], ['auth' => true, 'allow_forced' => true]);
$r->add('POST', '/password/change', [AuthController::class, 'changePassword'], ['auth' => true, 'allow_forced' => true]);

// Administrator
$r->add('GET', '/admin', [DashboardController::class, 'admin'], ['auth' => true, 'perm' => 'dashboard.admin']);
$r->add('GET', '/admin/users', [UserController::class, 'index'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('GET', '/admin/users/new', [UserController::class, 'create'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('POST', '/admin/users', [UserController::class, 'store'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('GET', '/admin/users/{id}/edit', [UserController::class, 'edit'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('POST', '/admin/users/{id}', [UserController::class, 'update'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('POST', '/admin/users/{id}/active', [UserController::class, 'toggleActive'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('POST', '/admin/users/{id}/reset', [UserController::class, 'issueReset'], ['auth' => true, 'perm' => 'users.manage']);
$r->add('GET', '/admin/audit', [AuditController::class, 'index'], ['auth' => true, 'perm' => 'auditlogs.view']);

// Customers (permissions are enforced per route; marketing holds none of them)
$r->add('GET', '/customers', [CustomerController::class, 'search'], ['auth' => true, 'perm' => 'customers.search']);
$r->add('GET', '/customers/lookup', [CustomerController::class, 'lookup'], ['auth' => true, 'perm' => 'customers.search']);
$r->add('GET', '/customers/new', [CustomerController::class, 'create'], ['auth' => true, 'perm' => 'customers.create']);
$r->add('POST', '/customers', [CustomerController::class, 'store'], ['auth' => true, 'perm' => 'customers.create']);
$r->add('POST', '/customers/selection/clear', [CustomerController::class, 'clearSelection'], ['auth' => true, 'perm' => 'customers.search']);
$r->add('GET', '/customers/{id}', [CustomerController::class, 'show'], ['auth' => true, 'perm' => 'customers.search']);
$r->add('POST', '/customers/{id}/select', [CustomerController::class, 'select'], ['auth' => true, 'perm' => 'customers.search']);
$r->add('GET', '/customers/{id}/edit', [CustomerController::class, 'edit'], ['auth' => true, 'perm' => 'customers.manage']);
$r->add('POST', '/customers/{id}/edit', [CustomerController::class, 'update'], ['auth' => true, 'perm' => 'customers.manage']);
$r->add('POST', '/customers/{id}/archive', [CustomerController::class, 'archive'], ['auth' => true, 'perm' => 'customers.manage']);
$r->add('GET', '/customers/{id}/source', [CustomerController::class, 'sourceForm'], ['auth' => true, 'perm' => 'customers.correct_source']);
$r->add('POST', '/customers/{id}/source', [CustomerController::class, 'sourceCorrect'], ['auth' => true, 'perm' => 'customers.correct_source']);

// Entry channels: bound to exactly one role each
$r->add('GET', '/reception', [DashboardController::class, 'reception'], ['auth' => true, 'perm' => 'dashboard.entry', 'role' => 'receptionist']);
$r->add('GET', '/call-operator', [DashboardController::class, 'callOperator'], ['auth' => true, 'perm' => 'dashboard.entry', 'role' => 'call_operator']);
$r->add('GET', '/social-media', [DashboardController::class, 'socialMedia'], ['auth' => true, 'perm' => 'dashboard.entry', 'role' => 'social_tracker']);

// Interaction questionnaires: one bound route set per channel/role. The channel is never a form field.
foreach ([['/reception', 'receptionist', 'reception'], ['/call-operator', 'call_operator', 'call'], ['/social-media', 'social_tracker', 'social']] as [$base, $roleCode, $m]) {
    $r->add('GET', $base . '/interactions/new', [InteractionController::class, $m . 'Form'], ['auth' => true, 'perm' => 'interactions.create', 'role' => $roleCode]);
    $r->add('POST', $base . '/interactions', [InteractionController::class, $m . 'Store'], ['auth' => true, 'perm' => 'interactions.create', 'role' => $roleCode]);
}
// Viewing/editing: channel scoping and the 24-hour rule are enforced in the controller/service.
$r->add('GET', '/interactions', [InteractionController::class, 'index'], ['auth' => true, 'perm' => 'interactions.view_own']);
$r->add('GET', '/interactions/{id}', [InteractionController::class, 'show'], ['auth' => true, 'perm' => 'interactions.view_own']);
$r->add('GET', '/interactions/{id}/edit', [InteractionController::class, 'edit'], ['auth' => true, 'perm' => 'interactions.view_own']);
$r->add('POST', '/interactions/{id}/edit', [InteractionController::class, 'update'], ['auth' => true, 'perm' => 'interactions.view_own']);
$r->add('GET', '/customers/{id}/appointments', [InteractionController::class, 'appointments'], ['auth' => true, 'perm' => 'interactions.create']);

// Marketing (aggregate analytics only)
$r->add('GET', '/marketing', [DashboardController::class, 'marketing'], ['auth' => true, 'perm' => 'dashboard.marketing']);

return $r;
