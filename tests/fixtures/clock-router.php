<?php
declare(strict_types=1);

// Development-server router for Phase 6 HTTP tests: `php -S 127.0.0.1:PORT -t public tests/fixtures/clock-router.php`.
// When PARKNGO_TEST_CLOCK_FILE holds a UTC timestamp, billing decisions use it (Clock refuses outside *_test databases).
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$public = realpath(dirname(__DIR__, 2) . '/public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = realpath($public . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && !str_ends_with($file, '.php')) return false;
$clockFile = getenv('PARKNGO_TEST_CLOCK_FILE') ?: '';
if ($clockFile !== '' && is_file($clockFile)) {
    $value = trim((string) file_get_contents($clockFile));
    if ($value !== '') define('PARKNGO_TEST_CLOCK', $value);
}
$scripts = ['/api.php' => 'api.php', '/maya-webhook.php' => 'maya-webhook.php'];
require $public . '/' . ($scripts[$path] ?? 'index.php');
