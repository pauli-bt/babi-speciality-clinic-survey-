<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Env;
use App\Helpers\Http;
use App\Helpers\View;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth;
use App\Services\UserService;

final class UserController
{
    public function index(): void
    {
        View::render('admin/users/index', ['title' => 'Users', 'users' => User::all()]);
    }

    public function create(): void
    {
        View::render('admin/users/form', ['title' => 'New user', 'target' => null, 'roles' => Role::all(), 'errors' => [], 'old' => []]);
    }

    public function store(): void
    {
        $res = UserService::create(Auth::user(), $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('admin/users/form', ['title' => 'New user', 'target' => null, 'roles' => Role::all(), 'errors' => $res['errors'], 'old' => $_POST]);
            return;
        }
        // Rendered directly (never stored in the session) so a generated password is shown exactly once.
        View::render('admin/users/created', ['title' => 'User created', 'created' => User::findWithRole($res['id']), 'password' => $res['password']]);
    }

    public function edit(int $id): void
    {
        $target = User::findWithRole($id);
        if (!$target) {
            Http::abort(404);
        }
        View::render('admin/users/form', ['title' => 'Edit user', 'target' => $target, 'roles' => Role::all(), 'errors' => [], 'old' => []]);
    }

    public function update(int $id): void
    {
        $target = User::findWithRole($id);
        if (!$target) {
            Http::abort(404);
        }
        $res = UserService::update(Auth::user(), $id, $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('admin/users/form', ['title' => 'Edit user', 'target' => $target, 'roles' => Role::all(), 'errors' => $res['errors'], 'old' => $_POST]);
            return;
        }
        Http::flash('success', 'User updated.');
        Http::redirect('/admin/users');
    }

    public function toggleActive(int $id): void
    {
        $target = User::findWithRole($id);
        if (!$target) {
            Http::abort(404);
        }
        $res = UserService::setActive(Auth::user(), $id, (int) $target['is_active'] !== 1);
        Http::flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Account status updated.' : implode(' ', $res['errors']));
        Http::redirect('/admin/users');
    }

    public function issueReset(int $id): void
    {
        $token = UserService::issueReset(Auth::user(), $id);
        if ($token === null) {
            Http::flash('danger', 'A reset link can only be issued for an active account.');
            Http::redirect('/admin/users');
        }
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        View::render('admin/users/reset_link', ['title' => 'Reset link', 'target' => User::findWithRole($id), 'link' => $base . '/reset-password?token=' . $token]);
    }
}
