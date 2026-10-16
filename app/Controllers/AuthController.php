<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Http;
use App\Helpers\View;
use App\Middleware\RateLimiter;
use App\Services\Auth;
use App\Services\UserService;

final class AuthController
{
    public function showLogin(): void
    {
        View::render('auth/login', ['title' => 'Sign in', 'username' => '', 'error' => null], 'auth');
    }

    public function login(): void
    {
        $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $res = Auth::attempt($username, $password);
        if ($res['ok']) {
            Http::redirect('/');
        }
        http_response_code($res['locked'] ? 429 : 401);
        View::render('auth/login', ['title' => 'Sign in', 'username' => $username, 'error' => $res['error']], 'auth');
    }

    public function logout(): void
    {
        Auth::logout();
        Http::redirect('/login');
    }

    public function showChangePassword(): void
    {
        View::render('auth/change_password', ['title' => 'Change password', 'errors' => [], 'forced' => (int) Auth::user()['must_change_password'] === 1]);
    }

    public function changePassword(): void
    {
        $user = Auth::user();
        $res = UserService::changeOwnPassword(
            $user,
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if ($res['ok']) {
            Http::flash('success', 'Your password was changed.');
            Http::redirect('/');
        }
        http_response_code(422);
        View::render('auth/change_password', ['title' => 'Change password', 'errors' => $res['errors'], 'forced' => (int) $user['must_change_password'] === 1]);
    }

    public function showReset(): void
    {
        $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
        $this->guardResetRate();
        $username = UserService::resetUsername($token);
        if ($username === null) {
            if ($token !== '') { // opening the page without a token must not count as a failed attempt (it would let anyone lock the login page for an IP)
                RateLimiter::record('__reset__', Http::ip(), false);
            }
            http_response_code(400);
            View::render('auth/reset_password', ['title' => 'Reset password', 'valid' => false, 'token' => '', 'errors' => ['This reset link is invalid or has expired. Ask an administrator for a new one.']], 'auth');
            return;
        }
        View::render('auth/reset_password', ['title' => 'Reset password', 'valid' => true, 'token' => $token, 'errors' => []], 'auth');
    }

    public function reset(): void
    {
        $this->guardResetRate();
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $res = UserService::completeReset($token, (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
        if ($res['ok']) {
            Http::flash('success', 'Password updated. You can now sign in.');
            Http::redirect('/login');
        }
        RateLimiter::record('__reset__', Http::ip(), false);
        http_response_code(422);
        View::render('auth/reset_password', ['title' => 'Reset password', 'valid' => UserService::resetUsername($token) !== null, 'token' => $token, 'errors' => $res['errors']], 'auth');
    }

    private function guardResetRate(): void
    {
        if (RateLimiter::ipLocked(Http::ip())) {
            Http::abort(429, 'Too many attempts. Please wait a few minutes and try again.');
        }
    }
}
