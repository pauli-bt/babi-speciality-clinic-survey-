<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\{Db, Env, SqlRunner};
use App\Models\User;
use App\Validation\{PasswordPolicy, UserValidator};

/**
 * One-time database setup for hosts that give no terminal.
 * Only active while SETUP_TOKEN (16+ characters) is set; the request must carry the same value as ?token=.
 * Runs the migrations (repeat-safe) and creates the FIRST administrator from BABI_ADMIN_* when none exists.
 * Remove SETUP_TOKEN and BABI_ADMIN_PASSWORD afterwards.
 */
final class WebSetup
{
    public static function enabled(): bool
    {
        return strlen((string) Env::get('SETUP_TOKEN', '')) >= 16;
    }

    public static function authorised(): bool
    {
        $sent = $_GET['token'] ?? '';
        return self::enabled() && is_string($sent) && hash_equals((string) Env::get('SETUP_TOKEN'), $sent);
    }

    public static function run(): string
    {
        $out = [];
        $pdo = Db::pdo(true);
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $root = BASE_PATH . '/database';

        if (!in_array('001_phase1_baseline', $applied, true)) {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'customers'");
            if ((int) $has->fetchColumn() === 0) {
                foreach (['/schema.sql', '/triggers.sql', '/seeders/reference_data.sql'] as $f) {
                    $out[] = "baseline: loaded $f (" . SqlRunner::runFile($pdo, $root . $f) . ' statements)';
                }
            } else {
                $out[] = 'baseline: tables already exist, data left untouched';
            }
            $record->execute(['001_phase1_baseline']);
        }
        $files = glob($root . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            $out[] = "migrated: $version (" . SqlRunner::runFile($pdo, $file) . ' statements)';
            $record->execute([$version]);
        }
        $out[] = 'database is up to date';

        $out[] = self::createAdmin();
        return implode("\n", $out);
    }

    private static function createAdmin(): string
    {
        if (User::countActiveAdmins() > 0) {
            return 'admin: an active administrator already exists, nothing created';
        }
        $username = UserValidator::normalizeUsername((string) Env::get('BABI_ADMIN_USERNAME', ''));
        $name = trim((string) Env::get('BABI_ADMIN_NAME', ''));
        $password = (string) Env::get('BABI_ADMIN_PASSWORD', '');
        if ($username === '' || $name === '' || $password === '') {
            return 'admin: NOT created. Set BABI_ADMIN_USERNAME, BABI_ADMIN_NAME and BABI_ADMIN_PASSWORD, then reload this page.';
        }
        $roleId = (int) Db::pdo()->query("SELECT id FROM roles WHERE code='admin'")->fetchColumn();
        $errors = array_merge(UserValidator::usernameErrors($username), UserValidator::profileErrors($name, '', $roleId), PasswordPolicy::validate($password, $username));
        if (User::usernameTaken($username)) {
            $errors[] = 'That username is already in use.';
        }
        if ($errors) {
            return 'admin: NOT created. ' . implode(' ', $errors);
        }
        $id = User::insert($name, $username, null, Auth::hash($password), $roleId, false, null);
        Audit::log(null, 'user.bootstrap_admin', 'user', $id, null, ['username' => $username]);
        return "admin: '$username' created. You can sign in now.";
    }
}
