<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Payments\PaymentService;

try {
    $row = Database::connection()->query("SELECT user_id, reference FROM payment_attempts WHERE maya_checkout_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row) throw new RuntimeException('No Maya checkout attempt exists to verify.');
    $payment = (new PaymentService())->verifyInitialPayment((int) $row['user_id'], $row['reference']);
    echo json_encode([
        'reference' => $payment['reference'],
        'local_status' => $payment['local_status'],
        'provider_status' => $payment['provider_status'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
