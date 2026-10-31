<?php
declare(strict_types=1);

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
    $config = require BASE_PATH . '/config/maya.php';
    if ($config['mode'] !== 'sandbox') { http_response_code(404); exit; }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, $config['webhook_ip_allowlist'], true)) { http_response_code(403); exit; }
    $body = file_get_contents('php://input');
    $payload = (new \ParkNGo\Services\Payments\PaymentService())->verifyWebhookSignature($body, $_SERVER['HTTP_X_MAYA_WEBHOOK_SIGNATURE'] ?? '', $_SERVER['HTTP_X_MAYA_WEBHOOK_TIMESTAMP'] ?? '');
    (new \ParkNGo\Services\Payments\PaymentService())->handleVerifiedWebhook($payload);
    http_response_code(200); echo 'OK';
} catch (\InvalidArgumentException $error) { http_response_code(400); echo 'Rejected'; }
catch (\Throwable $error) { error_log('Maya webhook failed: ' . get_class($error)); http_response_code(503); echo 'Retry'; }
