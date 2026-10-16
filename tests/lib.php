<?php
declare(strict_types=1);
// Shared test helpers (HTTP client, assertions, DB access).
$PW = getenv('PW');
putenv('DB_HOST=localhost'); putenv('DB_USER=babi_app'); putenv("DB_PASS=$PW"); putenv('DB_NAME=babi_test'); putenv('APP_ENV=testing');
require __DIR__ . '/../app/bootstrap.php';

$pass = 0; $fails = [];
function t(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fails;
    if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fails[] = $name; echo "  FAIL  $name" . ($detail ? "  [$detail]" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function admin_pdo(string $db): PDO {
    global $PW;
    return new PDO("mysql:host=localhost;dbname=$db;charset=utf8mb4", 'babi_migrate', $PW, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function throws(PDO $p, string $sql): ?string { try { $p->exec($sql); return null; } catch (PDOException $e) { return $e->getMessage(); } }

final class Client {
    public string $jar;
    public function __construct(public string $base = 'http://127.0.0.1:8099') { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function req(string $method, string $path, ?array $data = null, array $headers = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
        if ($data !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);
        $h = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $h, $m);
        return ['status' => $status, 'headers' => $h, 'body' => substr($raw, $hs), 'loc' => $m[1] ?? ''];
    }
    public function csrf(string $page): string { $r = $this->req('GET', $page); return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : ''; }
    public function post(string $formPage, string $action, array $data): array { $data['_csrf'] = $this->csrf($formPage); return $this->req('POST', $action, $data); }
    public function login(string $u, string $p): array { return $this->post('/login', '/login', ['username' => $u, 'password' => $p]); }
    public function cookie(string $name = 'babi_sid'): ?string {
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $l = preg_replace('/^#HttpOnly_/', '', $l);
            if ($l === '' || $l[0] === '#') continue;
            $p = explode("\t", $l);
            if (($p[5] ?? '') === $name) return $p[6] ?? null;
        }
        return null;
    }
    public function setCookie(string $v, string $name = 'babi_sid'): void { file_put_contents($this->jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\t$name\t$v\n"); }
}
function adminForm(Client $c, array $d): array { return $c->post('/admin/users/new', '/admin/users', $d); }

function finish(string $label): never {
    global $pass, $fails;
    echo "\n==================================\n";
    echo "$label  Passed: $pass   Failed: " . count($fails) . "\n";
    foreach ($fails as $f) echo "  - $f\n";
    exit(count($fails) ? 1 : 0);
}
