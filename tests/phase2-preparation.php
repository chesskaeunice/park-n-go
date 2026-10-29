<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Api\Json;

$checks = 0;
function verify(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    ++$checks; echo "PASS $label\n";
}
function rejects(callable $action, string $exception, string $label): void
{
    try { $action(); }
    catch (Throwable $error) { verify($error instanceof $exception, $label); return; }
    verify(false, $label);
}

try {
    $envelope = ['data' => ['reference' => 'LOCAL-CODEC-TEST', 'amount' => 5000, 'currency' => 'PHP', 'label' => 'Café · parking']];
    $encoded = Json::encode($envelope);
    $decoded = Json::decode($encoded);
    verify($decoded->data->amount === 5000 && $decoded->data->currency === 'PHP', 'JSON centavo integer round trip');
    verify($decoded->data->label === 'Café · parking', 'Unicode JSON round trip');
    verify(Json::encode([]) === '{}', 'empty JSON object envelope');
    verify(Json::decode('{"id":92233720368547758080}')->id === '92233720368547758080', 'large identifiers avoid float precision loss');
    rejects(fn() => Json::decode('{invalid'), JsonException::class, 'malformed JSON rejected');
    rejects(fn() => Json::decode('[]'), UnexpectedValueException::class, 'root arrays rejected');
    rejects(fn() => Json::decode('null'), UnexpectedValueException::class, 'null envelope rejected');
    rejects(fn() => Json::decode('{"data":{}}', 5), LengthException::class, 'oversized payload rejected');
    rejects(fn() => Json::encode(['invalid' => "\xB1"]), JsonException::class, 'invalid UTF-8 encoding rejected');
    rejects(fn() => Json::decode('{"invalid":"' . "\xB1" . '"}'), JsonException::class, 'invalid UTF-8 decoding rejected');
    rejects(fn() => Json::decode(str_repeat('{"x":', 70) . '{}' . str_repeat('}', 70)), JsonException::class, 'excessively nested JSON rejected');
    // Isolated process-only dummy configuration, not a provider credential or payment fixture.
    putenv('PAYMONGO_SECRET_KEY='); putenv('PAYMONGO_WEBHOOK_SECRET='); putenv('PAYMENT_MODE=sandbox');
    $config = require BASE_PATH . '/config/payments.php';
    verify($config['provider'] === 'paymongo' && !$config['enabled'] && $config['implementation_status'] === 'pending_phase_6', 'PayMongo preparation cannot enable payment processing');
    verify($config['secret_key'] === '' && $config['webhook_secret'] === '' && $config['mode'] === 'sandbox', 'startup does not require provider credentials');
    putenv('PAYMONGO_SECRET_KEY=local-test-placeholder');
    $config = require BASE_PATH . '/config/payments.php';
    verify($config['secret_key'] === 'local-test-placeholder' && !$config['enabled'], 'credential configuration stays server-side and disabled');
    $routes = require BASE_PATH . '/routes/web.php';
    verify(!isset($routes['checkout']) && !isset($routes['paymongo-webhook']), 'no premature payment or webhook routes');
    echo "\n$checks offline preparation checks passed. No network requests or database writes performed.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
