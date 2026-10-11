<?php
declare(strict_types=1);

/**
 * Applies pending database migrations. Safe to run repeatedly.
 *  - Fresh database: loads the Phase 1 baseline (schema.sql, triggers.sql, reference data).
 *  - Existing Phase 1 database: baseline is recorded as applied WITHOUT touching any data.
 *  - Then applies database/migrations/NNN_*.sql in order, each exactly once.
 * Uses DB_MIGRATE_USER / DB_MIGRATE_PASS (DDL rights) when set, otherwise DB_USER / DB_PASS.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\{Db, SqlRunner};

$pdo = Db::pdo(true);
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
$root = BASE_PATH . '/database';

if (!in_array('001_phase1_baseline', $applied, true)) {
    $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'customers'");
    $has->execute();
    if ((int) $has->fetchColumn() === 0) {
        foreach (['/schema.sql', '/triggers.sql', '/seeders/reference_data.sql'] as $f) {
            echo "baseline: loading $f (" . SqlRunner::runFile($pdo, $root . $f) . " statements)\n";
        }
    } else {
        echo "baseline: existing Phase 1 schema detected, data left untouched\n";
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
    echo "migrating: $version (" . SqlRunner::runFile($pdo, $file) . " statements)\n";
    $record->execute([$version]);
}
echo "database is up to date\n";
