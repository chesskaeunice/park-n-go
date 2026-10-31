<?php
declare(strict_types=1);

use ParkNGo\Services\Environment;

// Deterministic provider tests may target a loopback fake Maya, never outside an isolated *_test database.
$testOrigin = Environment::get('MAYA_TEST_API_ORIGIN');
$useTestOrigin = $testOrigin !== '' && Environment::get('APP_ENV', 'local') === 'local'
    && str_ends_with(Environment::get('DB_DATABASE'), '_test') && preg_match('#^http://127\.0\.0\.1:\d{2,5}$#D', $testOrigin) === 1;

return [
    'enabled' => Environment::get('MAYA_ENABLED', 'false') === 'true',
    'mode' => Environment::get('MAYA_MODE', 'sandbox'),
    'api_origin' => $useTestOrigin ? $testOrigin : 'https://pg-sandbox.paymaya.com',
    'public_key' => Environment::get('MAYA_PUBLIC_KEY'),
    'secret_key' => Environment::get('MAYA_SECRET_KEY'),
    'webhook_key_1' => Environment::get('MAYA_WEBHOOK_PUBLIC_KEY_1'),
    'webhook_key_2' => Environment::get('MAYA_WEBHOOK_PUBLIC_KEY_2'),
    'webhook_ip_allowlist' => ['13.229.160.234', '3.1.199.75'],
    'webhook_tolerance_ms' => 300000,
];
