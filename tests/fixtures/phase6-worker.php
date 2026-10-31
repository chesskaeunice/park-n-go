<?php
declare(strict_types=1);

// Separate-process worker for tests/phase6-concurrency.php. Each worker owns its own database connection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use ParkNGo\Services\{Database, Environment};
use ParkNGo\Services\Parking\{AvailabilityWindow, ReservationConflict, ReservationService};
use ParkNGo\Services\Payments\PaymentService;

if (Environment::get('APP_ENV', 'local') !== 'local' || !str_ends_with(Environment::get('DB_DATABASE'), '_test')) exit(2);
try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    if (!preg_match('/^[a-f0-9]{16}$/D', (string) $input['barrier'])) throw new RuntimeException('Invalid barrier.');
    $directory = BASE_PATH . '/storage/test-runtime/phase6-barriers';
    Database::connection()->exec('SET SESSION innodb_lock_wait_timeout=10');
    file_put_contents($directory . '/' . $input['barrier'] . '-' . (int) $input['worker'] . '.ready', 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($directory . '/' . $input['barrier'] . '.go')) { if (microtime(true) > $deadline) throw new RuntimeException('Barrier timed out.'); usleep(2000); }
    $payments = new PaymentService();
    $result = match ($input['mode']) {
        'extend' => ['result' => 'url', 'url' => $payments->startExtensionPayment((int) $input['user'], (int) $input['session'], $input['blocks'])],
        'overtime' => ['result' => 'url', 'url' => $payments->startOvertimeSettlement((int) $input['user'], (int) $input['session'])],
        'webhook' => (function () use ($payments, $input): array { $payments->handleVerifiedWebhook($input['payload']); return ['result' => 'done']; })(),
        'poll' => ['result' => 'done', 'status' => $payments->verifyPayment((int) $input['user'], (string) $input['reference'])['local_status']],
        'poll-initial' => ['result' => 'done', 'status' => $payments->verifyInitialPayment((int) $input['user'], (string) $input['reference'])['local_status']],
        'sweep' => ['result' => 'done', 'expired' => $payments->expireSessionCheckouts()],
        'reserve' => ['result' => 'created', 'id' => (new ReservationService())->create((int) $input['user'], (int) $input['slot'], new AvailabilityWindow($input['window']), $input['key'], $input['quote_hash'])],
    };
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (ReservationConflict|InvalidArgumentException|OutOfBoundsException $error) {
    echo json_encode(['result' => 'conflict', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['result' => 'error', 'class' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR); exit(1);
}
