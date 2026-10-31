<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\{Auth, Database, KioskService};

if (getenv('APP_ENV') !== 'local' || !str_ends_with(getenv('DB_DATABASE') ?: '', '_test')) { fwrite(STDERR, "Use an isolated *_test database.\n"); exit(1); }
$pdo = Database::connection();
$admin = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status='active' ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$admin) {
    $run = strtolower(bin2hex(random_bytes(5)));
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'admin')")->execute(['HTTP Kiosk Admin', 'http-kiosk-admin-' . $run . '@parkngo.test', Auth::hashPassword('fixture-password')]);
    $admin = (int) $pdo->lastInsertId();
}
$facility = (int) $pdo->query('SELECT id FROM parking_facilities WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
if (!$facility) {
    $run = strtolower(bin2hex(random_bytes(5)));
    $pdo->prepare('INSERT INTO parking_facilities (name,address,city) VALUES (?,?,?)')->execute(['HTTP Kiosk Facility ' . $run, 'Test address', 'Test city']);
    $facility = (int) $pdo->lastInsertId();
}
$fixture = KioskService::create($admin, $facility, 'HTTP Gate');
$cookie = tempnam(sys_get_temp_dir(), 'parkngo-kiosk-http-');

function httpRequest(string $url, string $cookie, string $method = 'GET', array $fields = [], bool $follow = false): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $fields, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($handle); if ($body === false) throw new RuntimeException(curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
    return [$status, (string) $body];
}

try {
    [$status, $body] = httpRequest('http://127.0.0.1:8091/index.php?page=kiosk-activate', $cookie);
    if ($status !== 200 || !preg_match('/name="csrf" value="([^"]+)"/', $body, $match)) throw new RuntimeException('Activation page or CSRF token unavailable.');
    if (!str_contains((string) file_get_contents($cookie), 'parkngo_kiosk_session')) throw new RuntimeException('Separate kiosk cookie unavailable.');
    [$status] = httpRequest('http://127.0.0.1:8091/index.php?page=kiosk-activate', $cookie, 'POST', ['code' => $fixture['code']]);
    if ($status !== 419) throw new RuntimeException('Missing CSRF was not rejected.');
    [$status] = httpRequest('http://127.0.0.1:8091/index.php?page=kiosk-activate', $cookie, 'POST', ['code' => $fixture['code'], 'csrf' => $match[1]]);
    if ($status !== 303) throw new RuntimeException('Activation did not return the expected redirect.');
    [$status, $body] = httpRequest('http://127.0.0.1:8091/index.php?page=kiosk-home', $cookie);
    if ($status !== 200 || !str_contains($body, 'Assigned facility')) throw new RuntimeException('Protected kiosk home unavailable.');
    [$status] = httpRequest('http://127.0.0.1:8091/index.php?page=admin-kiosks', $cookie);
    if (!in_array($status, [403, 303], true)) throw new RuntimeException('Kiosk session reached an admin route.');
    echo "PASS HTTP separate kiosk session, CSRF, activation, protected home, and admin isolation\n";
} finally {
    if (is_string($cookie) && is_file($cookie)) unlink($cookie);
}
