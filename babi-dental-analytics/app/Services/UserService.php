<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Db;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Validation\PasswordPolicy;
use App\Validation\UserValidator;

/** Account management. Every state change is transactional and audited. */
final class UserService
{
    /** @return array{ok: bool, errors: string[], id?: int, password?: string} */
    public static function create(array $actor, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $username = UserValidator::normalizeUsername((string) ($in['username'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $roleId = (int) ($in['role_id'] ?? 0);
        $password = (string) ($in['password'] ?? '');

        $errors = array_merge(UserValidator::profileErrors($name, $email, $roleId), UserValidator::usernameErrors($username));
        if ($errors === [] && User::usernameTaken($username)) {
            $errors[] = 'That username is already in use.';
        }
        if ($email !== '' && User::emailTaken($email)) {
            $errors[] = 'That email address is already in use.';
        }
        $generated = false;
        if ($password === '') {
            $password = PasswordPolicy::generate($username);
            $generated = true;
        } else {
            $errors = array_merge($errors, PasswordPolicy::validate($password, $username));
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $id = User::insert($name, $username, $email === '' ? null : $email, Auth::hash($password), $roleId, true, (int) $actor['id']);
            Audit::log((int) $actor['id'], 'user.create', 'user', $id, null, ['username' => $username, 'name' => $name, 'email' => $email, 'role' => Role::find($roleId)['code']]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return ['ok' => true, 'errors' => [], 'id' => $id, 'password' => $generated ? $password : ''];
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function update(array $actor, int $id, array $in): array
    {
        $target = User::findWithRole($id);
        if (!$target) {
            return ['ok' => false, 'errors' => ['User not found.']];
        }
        $name = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $roleId = (int) ($in['role_id'] ?? 0);

        $errors = UserValidator::profileErrors($name, $email, $roleId);
        if ($email !== '' && User::emailTaken($email, $id)) {
            $errors[] = 'That email address is already in use.';
        }
        $roleChanged = $roleId !== (int) $target['role_id'];
        if ($roleChanged && $id === (int) $actor['id']) {
            $errors[] = 'You cannot change your own role.';
        }
        $newRole = Role::find($roleId);
        if ($roleChanged && $newRole && $target['role_code'] === 'admin' && $newRole['code'] !== 'admin'
            && (int) $target['is_active'] === 1 && User::countActiveAdmins($id) === 0) {
            $errors[] = 'This is the last active administrator and cannot be demoted.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            User::updateProfile($id, $name, $email === '' ? null : $email, $roleId);
            if ($roleChanged) {
                User::bumpSessionVersion($id); // force re-login so new permissions apply immediately
                Audit::log((int) $actor['id'], 'user.role_change', 'user', $id, ['role' => $target['role_code']], ['role' => $newRole['code']]);
            }
            if ($name !== $target['name'] || ($email === '' ? null : $email) !== $target['email']) {
                Audit::log((int) $actor['id'], 'user.update', 'user', $id, ['name' => $target['name'], 'email' => $target['email']], ['name' => $name, 'email' => $email === '' ? null : $email]);
            }
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return ['ok' => true, 'errors' => []];
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function setActive(array $actor, int $id, bool $active): array
    {
        $target = User::findWithRole($id);
        if (!$target) {
            return ['ok' => false, 'errors' => ['User not found.']];
        }
        if (!$active) {
            if ($id === (int) $actor['id']) {
                return ['ok' => false, 'errors' => ['You cannot deactivate your own account.']];
            }
            if ($target['role_code'] === 'admin' && (int) $target['is_active'] === 1 && User::countActiveAdmins($id) === 0) {
                return ['ok' => false, 'errors' => ['This is the last active administrator and cannot be deactivated.']];
            }
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            User::setActive($id, $active);
            User::bumpSessionVersion($id);
            Audit::log((int) $actor['id'], $active ? 'user.activate' : 'user.deactivate', 'user', $id, ['is_active' => (int) $target['is_active']], ['is_active' => $active ? 1 : 0]);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return ['ok' => true, 'errors' => []];
    }

    /** Admin-issued, single-use, time-limited reset link. Returns the raw token once; only its SHA-256 is stored. */
    public static function issueReset(array $actor, int $id): ?string
    {
        $target = User::findWithRole($id);
        if (!$target || (int) $target['is_active'] !== 1) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $minutes = max(5, (int) Setting::get('password_reset_minutes', '60'));
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_by_user_id) VALUES (?, ?, NOW() + INTERVAL ' . $minutes . ' MINUTE, ?)')
                ->execute([$id, hash('sha256', $token), (int) $actor['id']]);
            User::bumpSessionVersion($id);
            Audit::log((int) $actor['id'], 'user.password_reset_issued', 'user', $id);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return $token;
    }

    public static function resetUsername(string $token): ?string
    {
        $row = self::openReset($token);
        return $row['username'] ?? null;
    }

    private static function openReset(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = Db::pdo()->prepare('SELECT pr.id, pr.user_id, u.username FROM password_resets pr JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_active = 1');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function completeReset(string $token, string $new, string $confirm): array
    {
        $row = self::openReset($token);
        if (!$row) {
            return ['ok' => false, 'errors' => ['This reset link is invalid or has expired. Ask an administrator for a new one.']];
        }
        $errors = PasswordPolicy::validate($new, $row['username']);
        if ($new !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
            $st->execute([$row['id']]);
            if ($st->rowCount() !== 1) { // lost a race: token already consumed
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['This reset link has already been used.']];
            }
            User::setPassword((int) $row['user_id'], Auth::hash($new), false);
            Audit::log((int) $row['user_id'], 'auth.password_reset_completed', 'user', $row['user_id']);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        return ['ok' => true, 'errors' => []];
    }

    /** @return array{ok: bool, errors: string[]} */
    public static function changeOwnPassword(array $user, string $current, string $new, string $confirm): array
    {
        $errors = [];
        if (!password_verify($current, $user['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The two new passwords do not match.';
        }
        if ($current !== '' && $new === $current) {
            $errors[] = 'The new password must be different from the current one.';
        }
        $errors = array_merge($errors, PasswordPolicy::validate($new, (string) $user['username']));
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            User::setPassword((int) $user['id'], Auth::hash($new), false);
            Audit::log((int) $user['id'], 'auth.password_changed', 'user', $user['id']);
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t;
        }
        $fresh = User::findWithRole((int) $user['id']);
        Auth::start((int) $user['id'], (int) $fresh['session_version']); // this session continues, all others die
        return ['ok' => true, 'errors' => []];
    }
}
