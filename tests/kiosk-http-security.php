<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Database;

if (getenv('APP_ENV') !== 'local' || !str_ends_with(getenv('DB_DATABASE') ?: '', '_test')) { fwrite(STDERR, "Use an isolated *_test database.\n"); exit(1); }
$pdo = Database::connection(); $run = strtolower(bin2hex(random_bytes(5))); $password = 'Admin-password-123';
$pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\'admin\')')->execute(['HTTP Admin ' . $run, 'http-admin-' . $run . '@parkngo.test', password_hash($password, PASSWORD_DEFAULT)]); $adminEmail = 'http-admin-' . $run . '@parkngo.test';
$pdo->prepare('INSERT INTO parking_facilities (name,address,city) VALUES (?,?,?)')->execute(['HTTP Facility ' . $run, 'Test address', 'Test city']); $facility = (int) $pdo->lastInsertId();
$base = 'http://127.0.0.1:8091/index.php';
$adminCookie = tempnam(sys_get_temp_dir(), 'parkngo-admin-'); $kioskCookie = tempnam(sys_get_temp_dir(), 'parkngo-kiosk-'); $unactivatedCookie = tempnam(sys_get_temp_dir(), 'parkngo-unactivated-'); $rateCookie = tempnam(sys_get_temp_dir(), 'parkngo-rate-');

function requestSecurity(string $url, string $cookie, string $method = 'GET', array $fields = []): array
{
    $handle = curl_init($url); curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $fields, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($handle); if ($body === false) throw new RuntimeException(curl_error($handle)); $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle); return [$status, (string) $body];
}
function csrfSecurity(string $body): string { if (!preg_match('/name="csrf" value="([^"]+)"/', $body, $match)) throw new RuntimeException('Missing CSRF token.'); return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'); }
function securityCheck(bool $condition, string $label): void { if (!$condition) throw new RuntimeException('FAIL ' . $label); echo "PASS $label\n"; }

try {
    [$status, $body] = requestSecurity($base . '?page=login', $adminCookie); $loginCsrf = csrfSecurity($body); securityCheck($status === 200, 'admin login page is reachable over HTTP');
    [$status] = requestSecurity($base . '?page=login', $adminCookie, 'POST', ['csrf' => $loginCsrf, 'email' => $adminEmail, 'password' => $password]); securityCheck($status === 303, 'admin login succeeds over HTTP');
    [$status, $body] = requestSecurity($base . '?page=admin-kiosks', $adminCookie); $adminCsrf = csrfSecurity($body); securityCheck($status === 200, 'authenticated admin can reach kiosk management');
    [$status] = requestSecurity($base . '?page=admin-kiosks', $adminCookie, 'POST', ['action' => 'register', 'facility_id' => $facility, 'name' => 'HTTP Security Kiosk', 'csrf' => $adminCsrf]); securityCheck($status === 303, 'admin kiosk registration is CSRF-protected and succeeds');
    [$status, $body] = requestSecurity($base . '?page=admin-kiosks', $adminCookie); if (!preg_match('/<code>([A-F0-9]{32})<\/code>/', $body, $match)) throw new RuntimeException('Activation code was not displayed once to the administrator.'); $activationCode = $match[1];
    securityCheck(str_contains((string) file_get_contents($adminCookie), 'parkngo_session'), 'admin uses the normal session cookie');
    [$status, $body] = requestSecurity($base . '?page=kiosk-activate', $kioskCookie); $kioskCsrf = csrfSecurity($body); securityCheck($status === 200, 'kiosk activation page is reachable without driver login');
    [$status] = requestSecurity($base . '?page=kiosk-activate', $kioskCookie, 'POST', ['code' => $activationCode]); securityCheck($status === 419, 'kiosk activation rejects missing CSRF');
    [$status] = requestSecurity($base . '?page=kiosk-activate', $kioskCookie, 'POST', ['code' => $activationCode, 'csrf' => $kioskCsrf]); securityCheck($status === 303, 'kiosk activation succeeds over HTTP');
    securityCheck(str_contains((string) file_get_contents($kioskCookie), 'parkngo_kiosk_session') && !str_contains((string) file_get_contents($kioskCookie), 'parkngo_session'), 'kiosk and admin cookies remain isolated');
    [$status, $body] = requestSecurity($base . '?page=kiosk-home', $kioskCookie); securityCheck($status === 200 && str_contains($body, 'Exit kiosk'), 'activated kiosk reaches protected home');
    [$status] = requestSecurity($base . '?page=kiosk-home', $unactivatedCookie); securityCheck($status === 403, 'unactivated browser is denied kiosk home');
    [$status, $body] = requestSecurity($base . '?page=kiosk-activate', $unactivatedCookie); $unactivatedCsrf = csrfSecurity($body); [$status] = requestSecurity($base . '?page=kiosk-verify', $unactivatedCookie, 'POST', ['csrf' => $unactivatedCsrf, 'code' => 'PNGO-INVALID']); securityCheck($status === 403, 'unactivated browser cannot submit protected verification');
    [$status, $body] = requestSecurity($base . '?page=kiosk-activate', $rateCookie); $rateCsrf = csrfSecurity($body); $statuses = []; for ($i = 0; $i < 6; $i++) { [$attempt] = requestSecurity($base . '?page=kiosk-activate', $rateCookie, 'POST', ['csrf' => $rateCsrf, 'code' => str_repeat('A', 31) . $i]); $statuses[] = $attempt; } securityCheck(in_array(429, $statuses, true) && count(array_filter($statuses, static fn(int $value): bool => $value === 422)) >= 3, 'repeated invalid activation attempts are rate-limited [' . implode(',', $statuses) . ']');
    [$status, $body] = requestSecurity($base . '?page=admin-kiosks', $adminCookie); $adminCsrf = csrfSecurity($body); if (!preg_match('/name="kiosk_id" value="(\d+)"/', $body, $match)) throw new RuntimeException('Registered kiosk id missing.'); $kioskId = $match[1]; [$status] = requestSecurity($base . '?page=admin-kiosks', $adminCookie, 'POST', ['action' => 'revoke', 'kiosk_id' => $kioskId]); securityCheck($status === 419, 'kiosk revocation rejects missing CSRF'); [$status] = requestSecurity($base . '?page=admin-kiosks', $adminCookie, 'POST', ['action' => 'revoke', 'kiosk_id' => $kioskId, 'csrf' => $adminCsrf]); securityCheck($status === 303, 'admin revocation succeeds with CSRF'); [$status] = requestSecurity($base . '?page=kiosk-home', $kioskCookie); securityCheck($status === 403, 'revoked kiosk loses access immediately');
    echo "HTTP SECURITY CHECKS PASSED\n";
} finally { foreach ([$adminCookie, $kioskCookie, $unactivatedCookie, $rateCookie] as $file) if (is_string($file) && is_file($file)) unlink($file); }
