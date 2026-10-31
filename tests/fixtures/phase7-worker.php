<?php
declare(strict_types=1);

// Separate-process worker for Phase 7 concurrency checks. Each worker owns its own database connection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use ParkNGo\Services\{Database, Environment};
use ParkNGo\Services\Admin\{OperationsMonitor, PricingService, RefundService, SessionExceptionService};
use ParkNGo\Services\Parking\{AvailabilityWindow, ReservationConflict, ReservationService};

if (Environment::get('APP_ENV', 'local') !== 'local' || !str_ends_with(Environment::get('DB_DATABASE'), '_test')) exit(2);
try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    if (!preg_match('/^[a-f0-9]{16}$/D', (string) $input['barrier'])) throw new RuntimeException('Invalid barrier.');
    $directory = BASE_PATH . '/storage/test-runtime/phase7-barriers';
    Database::connection()->exec('SET SESSION innodb_lock_wait_timeout=10');
    file_put_contents($directory . '/' . $input['barrier'] . '-' . (int) $input['worker'] . '.ready', 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($directory . '/' . $input['barrier'] . '.go')) { if (microtime(true) > $deadline) throw new RuntimeException('Barrier timed out.'); usleep(2000); }
    $admin = (int) ($input['admin'] ?? 0);
    $result = match ($input['mode']) {
        'correct' => ['result' => 'done', 'state' => (new OperationsMonitor())->correct($admin, (int) $input['slot'], $input['action'], $input['expected'], 'Concurrent correction test')['inventory_status']],
        'price' => ['result' => 'done', 'id' => (new PricingService())->schedule($admin, (int) $input['facility'], $input['values'], '', 'Concurrent pricing test', (int) $input['expected'])['id']],
        'approve' => ['result' => 'done', 'status' => (new RefundService())->decide($admin, (int) $input['refund'], true, 'Concurrent approval test')['status']],
        'request' => ['result' => 'done', 'reference' => (new RefundService())->request($admin, $input['payment'], $input['amount'], 'Concurrent refund request', $input['key'])['reference']],
        'reserve' => ['result' => 'done', 'id' => (new ReservationService())->create((int) $input['user'], (int) $input['slot'], new AvailabilityWindow($input['window']), $input['key'], $input['quote_hash'])],
        'exception' => ['result' => 'done', 'id' => (new SessionExceptionService())->close($admin, (int) $input['session'], (int) $input['facility'], 'emergency_closure', 'Concurrent exception closure test')['exception_id']],
    };
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (ReservationConflict|InvalidArgumentException|OutOfBoundsException $error) {
    echo json_encode(['result' => 'conflict', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['result' => 'error', 'class' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR); exit(1);
}
