<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
if (is_file(BASE_PATH . '/vendor/autoload.php')) require BASE_PATH . '/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'ParkNGo\\';
    if (!str_starts_with($class, $prefix)) return;
    $parts = explode('\\', substr($class, strlen($prefix)));
    $parts[0] = strtolower($parts[0]);
    $file = BASE_PATH . '/app/' . implode('/', $parts) . '.php';
    if (is_file($file)) require $file;
});
\ParkNGo\Services\Environment::load(BASE_PATH . '/.env');
$config = require BASE_PATH . '/config/app.php';
date_default_timezone_set($config['timezone']);

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/storage/logs/app.log');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (\ParkNGo\Services\Environment::get('APP_ENV', 'local') !== 'local' && !$https) {
        http_response_code(400);
        exit('HTTPS is required.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '1800');
    $requestedPage = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
    $isKioskSession = in_array($requestedPage, ['kiosk-activate', 'kiosk-home', 'kiosk-verify', 'kiosk-exit'], true);
    session_name($isKioskSession ? 'parkngo_kiosk_session' : 'parkngo_session');
    $sessionDirectory = BASE_PATH . '/storage/sessions';
    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Session storage is unavailable.');
    }
    if (!is_writable($sessionDirectory)) throw new RuntimeException('Session storage is not writable.');
    session_save_path($sessionDirectory);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => $isKioskSession ? 'Strict' : 'Lax']);
    if (!session_start()) throw new RuntimeException('Unable to start a secure session.');
    header('Cache-Control: no-store');
    if (\ParkNGo\Services\Environment::get('APP_ENV', 'local') === 'local'
        && str_ends_with(\ParkNGo\Services\Environment::get('DB_DATABASE'), '_test')) {
        header('X-Parkngo-Test-Database: ' . hash('sha256', \ParkNGo\Services\Environment::get('DB_HOST', '127.0.0.1') . ':' . \ParkNGo\Services\Environment::get('DB_PORT', '3306') . ':' . \ParkNGo\Services\Environment::get('DB_DATABASE')));
    }
    if ($https) header('Strict-Transport-Security: max-age=31536000');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Keep internal workflow, provider, database, and authorization details out of the UI. */
function ui_error_message(mixed $error): string
{
    return 'We could not complete that request. Please check your details and try again.';
}

/** Short public display value; full references remain unchanged in storage and form values. */
function short_reference(mixed $reference): string
{
    $value = strtoupper(trim((string) $reference));
    $value = preg_replace('/^[A-Z]+-/', '', $value) ?? $value;
    return substr($value, 0, 8);
}

function url(string $page = 'home', array $parameters = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $parameters);
}

function redirect(string $page, array $parameters = []): never
{
    header('Location: ' . url($page, $parameters), true, 303);
    exit;
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">';
}

function input(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function flash(?string $message = null): ?string
{
    if ($message !== null) { $_SESSION['flash'] = $message; return null; }
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $value;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'self'; frame-ancestors 'none'");
