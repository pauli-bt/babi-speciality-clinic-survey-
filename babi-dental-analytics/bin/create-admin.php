<?php
declare(strict_types=1);

/**
 * Creates the FIRST administrator. Refuses to run if an active administrator already exists.
 * Interactive:      php bin/create-admin.php
 * Non-interactive:  BABI_ADMIN_USERNAME=... BABI_ADMIN_NAME=... BABI_ADMIN_PASSWORD=... php bin/create-admin.php
 * No credentials are stored in the code or the repository.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\Db;
use App\Models\{Role, User};
use App\Services\{Audit, Auth};
use App\Validation\{PasswordPolicy, UserValidator};

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$fail = static function (string $m): never {
    fwrite(STDERR, "ERROR: $m\n");
    exit(1);
};
$ask = static function (string $label, bool $hidden = false): string {
    echo $label . ': ';
    if ($hidden && stripos(PHP_OS, 'WIN') !== 0) {
        system('stty -echo');
    }
    $v = trim((string) fgets(STDIN));
    if ($hidden && stripos(PHP_OS, 'WIN') !== 0) {
        system('stty echo');
        echo "\n";
    }
    return $v;
};

if (User::countActiveAdmins() > 0) {
    $fail('An active administrator already exists. Use the web interface to manage users.');
}
$username = UserValidator::normalizeUsername(getenv('BABI_ADMIN_USERNAME') ?: $ask('Administrator username'));
$name = trim(getenv('BABI_ADMIN_NAME') ?: $ask('Full name'));
$password = getenv('BABI_ADMIN_PASSWORD') ?: $ask('Password (min 12 chars)', true);
if (!getenv('BABI_ADMIN_PASSWORD')) {
    if ($password !== $ask('Repeat password', true)) {
        $fail('Passwords do not match.');
    }
}
$errors = array_merge(UserValidator::usernameErrors($username), UserValidator::profileErrors($name, '', (int) (Db::pdo()->query("SELECT id FROM roles WHERE code='admin'")->fetchColumn())), PasswordPolicy::validate($password, $username));
if (User::usernameTaken($username)) {
    $errors[] = 'That username is already in use.';
}
if ($errors) {
    $fail(implode(' ', $errors));
}
$roleId = (int) Db::pdo()->query("SELECT id FROM roles WHERE code='admin'")->fetchColumn();
$id = User::insert($name, $username, null, Auth::hash($password), $roleId, false, null);
Audit::log(null, 'user.bootstrap_admin', 'user', $id, null, ['username' => $username]);
echo "Administrator '$username' created. Sign in and create the other accounts from Users.\n";
