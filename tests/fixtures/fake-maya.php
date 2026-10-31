<?php
declare(strict_types=1);

// Deterministic loopback stand-in for Maya Sandbox, for `php -S 127.0.0.1:PORT tests/fixtures/fake-maya.php`.
// It never confirms anything by itself: tests set each checkout's provider status explicitly.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$state = getenv('FAKE_MAYA_STATE') ?: '';
if ($state === '' || !is_dir($state)) { http_response_code(500); exit('Fake Maya state directory is not configured.'); }
header('Content-Type: application/json');

$respond = static function (int $status, array $body): never { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; };
$mode = is_file($state . '/mode.json') ? (json_decode((string) file_get_contents($state . '/mode.json'), true) ?: []) : [];
$authorized = static function (string $key): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return $key !== '' && hash_equals('Basic ' . base64_encode($key . ':'), $header);
};
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/checkout/v1/checkouts') {
    if (!$authorized((string) getenv('FAKE_MAYA_PUBLIC_KEY'))) $respond(401, ['error' => 'Checkout requires the public key.']);
    if (($mode['delay_ms'] ?? 0) > 0) usleep((int) $mode['delay_ms'] * 1000);
    $lock = fopen($state . '/checkout-count', 'c+'); flock($lock, LOCK_EX);
    $count = (int) stream_get_contents($lock) + 1; ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) $count); flock($lock, LOCK_UN); fclose($lock);
    if (($mode['checkout'] ?? 'ok') === 'error500') $respond(500, ['error' => 'Simulated provider failure.']);
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $reference = $payload['requestReferenceNumber'] ?? null; $amount = $payload['totalAmount']['value'] ?? null;
    if (!is_string($reference) || !is_string($amount) || ($payload['totalAmount']['currency'] ?? '') !== 'PHP') $respond(400, ['error' => 'Invalid checkout payload.']);
    $id = 'fake-' . bin2hex(random_bytes(12));
    if (!is_dir($state . '/checkouts')) mkdir($state . '/checkouts');
    file_put_contents($state . '/checkouts/' . $id . '.json', json_encode(['reference' => $reference, 'amount' => $amount, 'currency' => 'PHP', 'status' => 'PAYMENT_PENDING', 'item' => $payload['items'][0]['name'] ?? '']));
    $respond(200, ['checkoutId' => $id, 'redirectUrl' => 'https://fake-maya.invalid/checkout?id=' . $id]);
}
// Refund evidence is read-only here too: tests write refunds/{id}.json; nothing creates or confirms a refund by itself.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/payments/v1/payments/([A-Za-z0-9_-]+)/refunds/([A-Za-z0-9_-]+)$#D', $path, $match)) {
    if (!$authorized((string) getenv('FAKE_MAYA_SECRET_KEY'))) $respond(401, ['error' => 'Refund retrieval requires the secret key.']);
    if (($mode['refund'] ?? 'ok') === 'error503') $respond(503, ['error' => 'Simulated provider outage.']);
    $file = $state . '/refunds/' . $match[2] . '.json';
    if (!is_file($file)) $respond(404, ['error' => 'Refund not found.']);
    $refund = json_decode((string) file_get_contents($file), true);
    if ($refund['payment_id'] !== $match[1]) $respond(404, ['error' => 'Refund not found for this payment.']);
    $respond(200, ['id' => $match[2], 'paymentId' => $refund['payment_id'], 'status' => $refund['status'], 'amount' => ['value' => $refund['amount'], 'currency' => $refund['currency'] ?? 'PHP']]);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/payments/v1/payments/([A-Za-z0-9_-]+)$#D', $path, $match)) {
    if (!$authorized((string) getenv('FAKE_MAYA_SECRET_KEY'))) $respond(401, ['error' => 'Retrieval requires the secret key.']);
    if (($mode['retrieve'] ?? 'ok') === 'error503') $respond(503, ['error' => 'Simulated provider outage.']);
    $file = $state . '/checkouts/' . $match[1] . '.json';
    if (!is_file($file)) $respond(404, ['error' => 'Payment not found.']);
    $checkout = json_decode((string) file_get_contents($file), true);
    $respond(200, ['id' => $match[1], 'requestReferenceNumber' => $checkout['reference'], 'paymentStatus' => $checkout['status'], 'totalAmount' => ['value' => $checkout['amount'], 'currency' => $checkout['currency']]]);
}
$respond(404, ['error' => 'Unknown fake Maya route.']);
