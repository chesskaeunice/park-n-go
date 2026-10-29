<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Environment;

// Run only after the isolated test database server has been stopped. No database writes.
$base = rtrim(Environment::get('TEST_BASE_URL', 'http://127.0.0.1:8088'), '/');
if (Environment::get('APP_ENV', 'local') !== 'local' || !str_ends_with(Environment::get('DB_DATABASE'), '_test')
    || !in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Use the isolated loopback test server and a *_test database.\n"); exit(1);
}
try {
    $curl = curl_init($base . '/index.php?page=login');
    curl_setopt_array($curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $headers = [];
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, string $line) use (&$headers): int {
        if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower($key)] = trim($value); } return strlen($line);
    });
    $body = curl_exec($curl);
    $fingerprint = hash('sha256', Environment::get('DB_HOST', '127.0.0.1') . ':' . Environment::get('DB_PORT', '3306') . ':' . Environment::get('DB_DATABASE'));
    if (!hash_equals($fingerprint, $headers['x-parkngo-test-database'] ?? '') || !is_string($body)
        || !preg_match('/name="csrf" value="([a-f0-9]{64})"/', $body, $match)) throw new RuntimeException('Test server preflight failed.');
    $csrf = $match[1];
    $sessionId = '';
    foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) {
        $parts = explode("\t", $cookie); if (($parts[5] ?? '') === 'parkngo_session') $sessionId = $parts[6];
    }
    if (!preg_match('/^[a-zA-Z0-9,-]+$/D', $sessionId)) throw new RuntimeException('Missing test session.');
    // Test-only session fixture: no privileged route is accessed and no account is changed.
    $path = BASE_PATH . '/storage/sessions/sess_' . $sessionId;
    file_put_contents($path, file_get_contents($path) . 'user_id|i:1;session_version|i:1;last_activity|i:' . time() . ';authenticated_at|i:' . time() . ';');
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/index.php?page=profile', CURLOPT_HTTPGET => true]);
    curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 503) throw new RuntimeException('Expected unavailable database before logout.');
    echo "PASS protected request reports database outage\n";
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/index.php?page=logout', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf' => $csrf])]);
    curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 303 || !str_contains($headers['location'] ?? '', 'page=login')) throw new RuntimeException('Logout was blocked by database outage.');
    echo "PASS logout succeeds despite database outage\n";
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/index.php?page=dashboard', CURLOPT_HTTPGET => true]);
    curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 303 || !str_contains($headers['location'] ?? '', 'page=login')) throw new RuntimeException('Authenticated session survived logout.');
    echo "PASS cleared session redirects without querying unavailable database\n\n3 outage checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
