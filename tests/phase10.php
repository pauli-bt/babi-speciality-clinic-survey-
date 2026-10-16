<?php
declare(strict_types=1);
// Phase 10 suite: configuration handling, fail-safe startup, migration consistency, release hygiene, frozen UI.
require __DIR__ . '/lib.php';

$db = admin_pdo('babi_test');
$root = BASE_PATH;
$cleanEnv = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => '/tmp'];
$php = function (string $code, array $env = []) use ($root, $cleanEnv): string {
    $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + $cleanEnv);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return trim($o);
};
$loadJson = function (string $content, array $keys) use ($php): array {
    $f = tempnam(sys_get_temp_dir(), 'env'); file_put_contents($f, $content);
    $out = $php('require "app/bootstrap.php"; \App\Helpers\Env::load(' . var_export($f, true) . '); $k = ' . var_export($keys, true) . '; echo json_encode(array_combine($k, array_map(fn($x) => \App\Helpers\Env::get($x), $k)));');
    @unlink($f);
    return json_decode($out, true) ?? ['_error' => $out];
};

section('A. .env parsing');
$r = $loadJson("APP_ENV=production   # comment\nAPP_URL=https://x.example # trailing\n# full line comment\nDB_USER=babi_app    # runtime\nDB_PASS=\"p#ss word # 1\"\nTRUST_PROXY='0'  # quoted then comment\nEMPTY=\nSPACED = value with spaces\n", ['APP_ENV', 'APP_URL', 'DB_USER', 'DB_PASS', 'TRUST_PROXY', 'EMPTY', 'SPACED']);
t('inline # comments are stripped from unquoted values', ($r['APP_ENV'] ?? '') === 'production' && ($r['APP_URL'] ?? '') === 'https://x.example' && ($r['DB_USER'] ?? '') === 'babi_app', json_encode($r));
t('quoted values keep # and spaces; a comment after the closing quote is ignored', ($r['DB_PASS'] ?? '') === 'p#ss word # 1' && ($r['TRUST_PROXY'] ?? '') === '0');
t('empty values and spaces around = are handled', ($r['EMPTY'] ?? 'x') === '' && ($r['SPACED'] ?? '') === 'value with spaces');
$r = $loadJson("\xEF\xBB\xBFAPP_ENV=local\r\nAPP_URL=http://localhost:8080\r\nDB_NAME=babi_dental\r\n", ['APP_ENV', 'APP_URL', 'DB_NAME']);
t('a UTF-8 BOM and Windows (CRLF) line endings do not corrupt the first key or any value', ($r['APP_ENV'] ?? '') === 'local' && ($r['APP_URL'] ?? '') === 'http://localhost:8080' && ($r['DB_NAME'] ?? '') === 'babi_dental', json_encode($r));
$r = $loadJson("# DB_PASS=nope\nURL=https://a.example/?x=1&y=2\n", ['DB_PASS', 'URL']);
t('commented-out keys stay unset; = inside a value is kept', (array_key_exists('DB_PASS', $r) && $r['DB_PASS'] === null) && ($r['URL'] ?? '') === 'https://a.example/?x=1&y=2');

section('B. APP_ENV fails safe (a typo must never switch off HTTPS enforcement)');
$prod = function (?string $v) use ($php): string { $env = $v === null ? [] : ['APP_ENV' => $v]; return $php('require "app/bootstrap.php"; echo \App\Helpers\Env::isProduction() ? "prod" : "dev";', $env); };
foreach ([[null, 'prod'], ['', 'prod'], ['production', 'prod'], ['Production', 'prod'], ['prod', 'prod'], ['staging', 'prod'], ['live', 'prod'], ['local', 'dev'], ['LOCAL', 'dev'], ['testing', 'dev']] as [$v, $exp]) {
    t('APP_ENV=' . ($v === null ? '(unset)' : json_encode($v)) . ' -> ' . ($exp === 'prod' ? 'production' : 'development'), $prod($v) === $exp);
}

section('C. .env.example is a safe, complete template');
$ex = file_get_contents($root . '/.env.example');
$kv = []; foreach (preg_split('/\R/', $ex) as $l) { $l = trim($l); if ($l !== '' && $l[0] !== '#' && str_contains($l, '=')) { [$k, $v] = explode('=', $l, 2); $kv[$k] = $v; } }
t('no value line carries an inline comment (copying the file verbatim must work)', !preg_grep('/\s#/', $kv));
$parsed = $loadJson($ex, ['APP_ENV', 'APP_URL', 'APP_TIMEZONE', 'TRUST_PROXY', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_MIGRATE_USER', 'DB_MIGRATE_PASS']);
t('safe defaults: production, https URL, Africa/Addis_Ababa, TRUST_PROXY 0, placeholder passwords', ($parsed['APP_ENV'] ?? '') === 'production' && str_starts_with($parsed['APP_URL'] ?? '', 'https://') && ($parsed['APP_TIMEZONE'] ?? '') === 'Africa/Addis_Ababa' && ($parsed['TRUST_PROXY'] ?? '') === '0' && ($parsed['DB_PASS'] ?? '') === 'change-me' && ($parsed['DB_MIGRATE_PASS'] ?? '') === 'change-me-too', json_encode($parsed));
t('the template user names match the documented accounts', ($parsed['DB_USER'] ?? '') === 'babi_app' && ($parsed['DB_MIGRATE_USER'] ?? '') === 'babi_migrate' && ($parsed['DB_NAME'] ?? '') === 'babi_dental');
t('the time zone in the template is a valid PHP zone', in_array($parsed['APP_TIMEZONE'] ?? '', DateTimeZone::listIdentifiers(), true));
$used = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname(); if (!str_ends_with($p, '.php') || str_contains($p, '/tests/') || str_contains($p, '/vendor/')) continue;
    if (preg_match_all("/Env::get\('([A-Z_]+)'/", (string) file_get_contents($p), $m)) { foreach ($m[1] as $k) { $used[$k] = true; } }
}
$missing = array_values(array_filter(array_keys($used), fn($k) => !array_key_exists($k, $kv) && !str_contains($ex, '# ' . $k . '=')));
t('every setting the code reads (Env::get) appears in .env.example', $missing === [], implode(', ', $missing));
t('.env.example documents SESSION_PATH, the optional setting', str_contains($ex, 'SESSION_PATH'));

section('D. The application fails safe when configuration is missing or wrong (real PHP servers)');
$serve = function (array $env, callable $fn) use ($root, $cleanEnv) {
    $p = proc_open([PHP_BINARY, '-S', '127.0.0.1:8095', '-t', 'public'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + $cleanEnv);
    try {
        for ($i = 0; $i < 40; $i++) { $s = @fsockopen('127.0.0.1', 8095, $e1, $e2, 0.2); if ($s) { fclose($s); break; } usleep(100000); }
        return $fn(new Client('http://127.0.0.1:8095'));
    } finally { proc_terminate($p); proc_close($p); }
};
$x = $serve([], fn($c) => $c->req('GET', '/login'));
t('no configuration at all -> 500 "Server misconfigured" (never an open, insecure site)', $x['status'] === 500 && str_contains($x['body'], 'Server misconfigured') && !str_contains($x['body'], '.php'));
$x = $serve(['APP_ENV' => 'prod', 'APP_URL' => 'https://clinic.example.org'], fn($c) => $c->req('GET', '/login'));
t('a typo in APP_ENV still forces HTTPS (301 to the https APP_URL)', $x['status'] === 301 && str_starts_with($x['loc'] ?? '', 'https://clinic.example.org/login'));
$x = $serve(['APP_ENV' => 'production', 'APP_URL' => 'http://clinic.example.org'], fn($c) => $c->req('GET', '/login'));
t('production with a non-https APP_URL is refused (500)', $x['status'] === 500 && str_contains($x['body'], 'https://'));
$x = $serve(['APP_ENV' => 'local', 'APP_TIMEZONE' => 'Mars/Olympus'], fn($c) => $c->req('GET', '/login'));
t('an invalid APP_TIMEZONE stops the application with a clear, generic message', $x['status'] === 500 && str_contains($x['body'], 'Server configuration error') && !str_contains($x['body'], 'Olympus'));
$x = $serve(['APP_ENV' => 'local', 'SESSION_PATH' => '/proc/nope/sessions'], fn($c) => $c->req('GET', '/login'));
t('an unwritable session folder stops the application with a clear message (no silent login loop, no path shown)', $x['status'] === 500 && str_contains($x['body'], 'session folder') && !str_contains($x['body'], '/proc'));
$x = $serve(['APP_ENV' => 'local', 'DB_HOST' => 'localhost', 'DB_NAME' => 'babi_test', 'DB_USER' => 'babi_app', 'DB_PASS' => 'definitely-wrong', 'SESSION_PATH' => sys_get_temp_dir() . '/babi_p10_sessions'], function ($c) { $tok = $c->csrf('/login'); return $c->req('POST', '/login', ['_csrf' => $tok, 'username' => 'x', 'password' => 'y']); });
t('a wrong database password gives a generic 500 without credentials or SQL text', $x['status'] === 500 && !preg_match('/SQLSTATE|Access denied|definitely-wrong|babi_app|PDO/i', $x['body']), "status={$x['status']}");

section('E. Migration consistency');
$files = array_map('basename', glob($root . '/database/migrations/*.sql')); sort($files);
$vers = array_map(fn($f) => basename($f, '.sql'), $files);
$nums = array_map(fn($v) => (int) substr($v, 0, 3), $vers);
t('migrations are numbered consecutively from 002 with no gaps or duplicates', $nums === range(2, 1 + count($nums)), implode(',', $nums));
t('migration names follow NNN_name.sql', count(preg_grep('/^\d{3}_[a-z0-9_]+\.sql$/', $files)) === count($files));
$recorded = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
t('the test database recorded the baseline plus every migration file, in order', $recorded === array_merge(['001_phase1_baseline'], $vers), json_encode($recorded));
$destructive = [];
foreach ($files as $f) { if (preg_match('/\b(DROP\s+(TABLE|DATABASE|COLUMN)|TRUNCATE|DELETE\s+FROM)\b/i', file_get_contents($root . '/database/migrations/' . $f))) { $destructive[] = $f; } }
t('no migration drops or deletes data', $destructive === [], implode(', ', $destructive));
t('baseline files used by bin/migrate.php exist', is_file($root . '/database/schema.sql') && is_file($root . '/database/triggers.sql') && is_file($root . '/database/seeders/reference_data.sql'));

section('F. Release hygiene and documentation accuracy');
$bad = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $n = $f->getFilename(); $p = $f->getPathname();
    if (str_contains($p, '/storage/')) { continue; } // runtime folders: checked in the release ZIP, not in a working copy that has been run
    if ($n === '.env' || preg_match('/\.(log|bak|swp|orig|tmp)$/', $n) || str_starts_with($n, 'sess_') || str_contains($p, '/.git/') || $n === '.DS_Store' || str_ends_with($n, '~')) { $bad[] = str_replace($root, '', $p); }
}
t('the project contains no .env, stray logs, backups or editor leftovers (storage/ is runtime and excluded from the ZIP)', $bad === [], implode(', ', $bad));
t('.gitignore excludes .env and runtime files', preg_match('/^\.env$/m', file_get_contents($root . '/.gitignore')) === 1 && str_contains(file_get_contents($root . '/.gitignore'), 'storage'));
$readme = file_get_contents($root . '/README.md');
foreach (['php bin/migrate.php', 'bin/create-admin.php', 'APP_ENV=production', 'DocumentRoot', 'TRUST_PROXY', 'mysqldump', 'php bin/check-timezone.php', 'bash tests/run.sh'] as $needle) { t("README documents: $needle", str_contains($readme, $needle)); }
$refs = []; preg_match_all('/`((?:bin|docs|tests|database|app|config|public|resources|storage)\/[A-Za-z0-9_\/.\-]+?\.(?:php|md|sql|sh|sha256))`/', $readme, $m);
foreach (array_unique($m[1]) as $f) { if (!file_exists($root . '/' . $f)) { $refs[] = $f; } }
t('every file path mentioned in the README exists', $refs === [], implode(', ', $refs));
foreach (['OPERATIONS_GUIDE', 'DEPLOYMENT_CHECKLIST', 'PHASE10_FINAL_AUDIT'] as $d) { t("docs/$d.md exists and is not empty", is_file("$root/docs/$d.md") && filesize("$root/docs/$d.md") > 1500); }
t('the Apache safety net is present in the root and public .htaccess files', str_contains(file_get_contents($root . '/.htaccess'), 'RewriteRule ^(?!public/)') && str_contains(file_get_contents($root . '/public/.htaccess'), 'index.php'));

section('G. Frozen UI (resources/ and public/assets/ unchanged since Phase 9)');
$man = file($root . '/tests/ui-manifest.sha256', FILE_IGNORE_NEW_LINES); $diff = []; $seen = [];
foreach ($man as $line) { [$h, $f] = preg_split('/\s+/', $line, 2); $seen[$f] = true; if (!is_file("$root/$f") || hash_file('sha256', "$root/$f") !== $h) { $diff[] = $f; } }
foreach (['resources', 'public/assets'] as $d) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS)) as $f) { $rel = ltrim(str_replace($root, '', $f->getPathname()), '/'); if (!isset($seen[$rel])) { $diff[] = "$rel (new)"; } } }
t('all ' . count($man) . ' UI files match the Phase 9 manifest (to refresh after an intentional UI edit see docs/PHASE10_FINAL_AUDIT.md)', $diff === [], implode(', ', array_slice($diff, 0, 5)));
t('the customised logo and stylesheet are among the frozen files', isset($seen['public/assets/images/babi-logo.png']) && isset($seen['public/assets/css/app.css']) && isset($seen['resources/views/layouts/app.php']) && isset($seen['resources/views/dashboard/admin.php']));

finish('Phase 10 suite');
