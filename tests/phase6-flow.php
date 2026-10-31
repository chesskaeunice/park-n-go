<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\{Auth, Database, Environment};
use ParkNGo\Services\Parking\{ParkingSessionService, ReservationConflict, ReservationService};
use ParkNGo\Services\Payments\{MayaApiException, PaymentService};
use ParkNGo\Services\Qr\TicketService;

if (Environment::get('APP_ENV', 'local') !== 'local' || !str_ends_with(Environment::get('DB_DATABASE'), '_test')) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated *_test database.\n"); exit(1);
}

$pdo = Database::connection(); $checks = 0; $run = strtoupper(bin2hex(random_bytes(5)));
function check6(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL ' . $label); ++$checks; echo "PASS $label\n"; }
function fixture6(PDO $pdo, string $run, int $index, bool $entryWindow = false, ?string $paymentStatus = 'checkout_created'): array
{
    $password = Auth::hashPassword('Fixture-' . bin2hex(random_bytes(8)));
    $email = 'phase6-' . strtolower($run) . '-' . $index . '-' . strtolower(bin2hex(random_bytes(3))) . '@parkngo.test';
    $pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')->execute(['Phase 6 Driver ' . $index, $email, $password, 'driver']); $driver = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')->execute(['Phase 6 Attendant ' . $index, 'attendant-' . strtolower($run) . '-' . $index . '-' . strtolower(bin2hex(random_bytes(3))) . '@parkngo.test', $password, 'attendant']); $attendant = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_facilities(name,address,city,description) VALUES(?,?,?,?)')->execute(['Phase 6 Facility ' . $run . '-' . $index, 'Test address', 'Test City', 'Isolated fixture']); $facility = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_floors(facility_id,label) VALUES(?,?)')->execute([$facility, 'Level 1']); $floor = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_slots(floor_id,label) VALUES(?,?)')->execute([$floor, 'T-' . $index]); $slot = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_rates(facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$facility, 5000, 2000, 2500]); $rate = (int) $pdo->lastInsertId();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC')); $start = $entryWindow ? $now->modify('-1 minute') : $now->modify('+1 hour'); $end = $start->modify('+1 hour');
    $reservationReference = 'PNG-' . strtoupper(bin2hex(random_bytes(10)));
    $snapshot = json_encode(['version'=>1,'rate'=>['initial_centavos'=>5000],'cancellation_cutoff_minutes'=>60], JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO reservations(reference,user_id,slot_id,rate_id,starts_at,ends_at,estimated_centavos,rate_snapshot) VALUES(?,?,?,?,?,?,?,?)')->execute([$reservationReference,$driver,$slot,$rate,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),5000,$snapshot]); $reservation = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO reservation_holds(user_id,slot_id,reservation_id,token_hash,starts_at,ends_at,expires_at) VALUES(?,?,?,?,?,?,?)')->execute([$driver,$slot,$reservation,hash('sha256',random_bytes(16)),$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$now->modify('+10 minutes')->format('Y-m-d H:i:s')]);
    $reference = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))); $checkout = 'maya-' . strtolower($run) . '-' . $index . '-' . strtolower(bin2hex(random_bytes(4)));
    if ($paymentStatus !== null) $pdo->prepare("INSERT INTO payment_attempts(reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,checkout_expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$reference,$driver,$reservation,'INITIAL_RESERVATION',5000,'PHP',$checkout,$reference,$paymentStatus,$now->modify('+10 minutes')->format('Y-m-d H:i:s')]);
    $pdo->prepare('INSERT INTO attendant_facilities(attendant_id,facility_id) VALUES(?,?)')->execute([$attendant,$facility]);
    return compact('driver','attendant','facility','reservation','reference','checkout','slot');
}
function provider6(array $fixture, string $status = 'PAYMENT_SUCCESS', string $amount = '50.00', string $currency = 'PHP', ?string $reference = null): Closure
{
    return static fn(string $id): stdClass => json_decode(json_encode(['id'=>$id,'requestReferenceNumber'=>$reference ?? $fixture['reference'],'paymentStatus'=>$status,'totalAmount'=>['value'=>$amount,'currency'=>$currency]], JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
}

try {
    $success = fixture6($pdo, $run, 1);
    $service = new PaymentService(provider6($success));
    $payment = $service->verifyInitialPayment($success['driver'], $success['reference']);
    $find = $pdo->prepare('SELECT status FROM reservations WHERE id=?'); $find->execute([$success['reservation']]);
    check6($payment['local_status'] === 'verified' && $find->fetchColumn() === 'confirmed', 'verified provider payment confirms one reservation');
    $payload = ['id'=>$success['checkout'],'requestReferenceNumber'=>$success['reference'],'paymentStatus'=>'PAYMENT_SUCCESS','totalAmount'=>['value'=>'50.00','currency'=>'PHP']];
    $service->handleVerifiedWebhook($payload); $service->handleVerifiedWebhook($payload);
    $find = $pdo->prepare('SELECT COUNT(*) FROM payment_attempt_events WHERE payment_attempt_id=(SELECT id FROM payment_attempts WHERE reference=?)'); $find->execute([$success['reference']]);
    $eventCount = (int) $find->fetchColumn(); check6($eventCount === 2, 'polling plus duplicate webhook remains idempotent (events=' . $eventCount . ')');

    $cancelService = new ReservationService();
    try { $cancelService->cancel($success['driver'], $success['reservation'], 1); check6(false, 'verified Maya payment blocks ordinary cancellation'); }
    catch (ReservationConflict $error) { check6(str_contains($error->getMessage(), 'refund workflow'), 'verified Maya payment blocks ordinary cancellation'); }

    $pendingCancel = fixture6($pdo, $run, 3, false, 'pending_verification');
    try { $cancelService->cancel($pendingCancel['driver'], $pendingCancel['reservation'], 1); check6(false, 'pending payment does not release its hold'); }
    catch (ReservationConflict $error) {
        $find = $pdo->prepare('SELECT released_at FROM reservation_holds WHERE reservation_id=?'); $find->execute([$pendingCancel['reservation']]);
        check6(str_contains($error->getMessage(), 'verification') && $find->fetchColumn() === null, 'pending payment does not release its hold');
    }

    foreach (['failed', 'expired', 'cancelled'] as $offset => $finalStatus) {
        $unpaid = fixture6($pdo, $run, 4 + $offset, false, $finalStatus);
        $cancelService->cancel($unpaid['driver'], $unpaid['reservation'], 1);
        $find = $pdo->prepare('SELECT status FROM reservations WHERE id=?'); $find->execute([$unpaid['reservation']]);
        check6($find->fetchColumn() === 'cancelled', $finalStatus . ' payment attempt permits ordinary unpaid cancellation');
    }

    $unpaid = fixture6($pdo, $run, 10, false, null);
    $cancelService->cancel($unpaid['driver'], $unpaid['reservation'], 1);
    $find = $pdo->prepare('SELECT status FROM reservations WHERE id=?'); $find->execute([$unpaid['reservation']]);
    check6($find->fetchColumn() === 'cancelled', 'reservation without payment attempt follows ordinary cancellation');

    $legacy = fixture6($pdo, $run, 11, false, null);
    $pdo->prepare("INSERT INTO payment_transactions(reservation_id,provider,idempotency_key,purpose,amount_centavos,currency,status) VALUES(?,?,?,?,?,?, 'paid')")->execute([$legacy['reservation'], 'legacy-test', 'legacy-' . strtolower($run), 'reservation', 5000, 'PHP']);
    try { $cancelService->cancel($legacy['driver'], $legacy['reservation'], 1); check6(false, 'legacy paid record blocks ordinary cancellation'); }
    catch (ReservationConflict $error) { check6(str_contains($error->getMessage(), 'refund workflow'), 'legacy paid record blocks ordinary cancellation'); }

    foreach (['PAYMENT_FAILED'=>'failed','PAYMENT_CANCELLED'=>'cancelled','PAYMENT_PENDING'=>'pending_verification'] as $providerStatus => $localStatus) {
        $fixture = fixture6($pdo, $run, random_int(10, 99)); $result = (new PaymentService(provider6($fixture, $providerStatus)))->verifyInitialPayment($fixture['driver'], $fixture['reference']);
        $find = $pdo->prepare('SELECT status FROM reservations WHERE id=?'); $find->execute([$fixture['reservation']]);
        check6($result['local_status'] === $localStatus && $find->fetchColumn() === 'pending', strtolower($providerStatus) . ' never confirms reservation');
        check6((new TicketService())->issue($fixture['driver'], $fixture['reservation'])['ticket'] === null, strtolower($providerStatus) . ' cannot activate QR');
    }

    foreach ([['bad reference', provider6($success, 'PAYMENT_SUCCESS', '50.00', 'PHP', 'PNGO-' . strtoupper(bin2hex(random_bytes(12))))], ['wrong amount', provider6($success, 'PAYMENT_SUCCESS', '49.99')], ['wrong currency', provider6($success, 'PAYMENT_SUCCESS', '50.00', 'USD')]] as [$label, $retriever]) {
        $fixture = fixture6($pdo, $run, random_int(100, 199)); $result = (new PaymentService($retriever))->verifyInitialPayment($fixture['driver'], $fixture['reference']);
        check6($result['local_status'] === 'pending_verification', $label . ' is held for verification');
    }

    $outage = fixture6($pdo, $run, 200); $result = (new PaymentService(static fn(string $id): stdClass => throw new MayaApiException('unavailable', 503)))->verifyInitialPayment($outage['driver'], $outage['reference']);
    check6($result['local_status'] === 'pending_verification', 'provider outage leaves payment pending verification');

    $refresh = fixture6($pdo, $run, 201); $calls = 0; $retriever = static function (string $id) use (&$calls, $refresh): stdClass { ++$calls; return provider6($refresh)($id); }; $refreshService = new PaymentService($retriever);
    $refreshService->verifyInitialPayment($refresh['driver'], $refresh['reference']); $refreshService->verifyInitialPayment($refresh['driver'], $refresh['reference']);
    check6($calls === 1, 'repeated refresh does not create another provider request or charge');

    $entry = fixture6($pdo, $run, 202, true); $entryService = new PaymentService(provider6($entry)); $entryService->verifyInitialPayment($entry['driver'], $entry['reference']);
    $ticket = (new TicketService())->issue($entry['driver'], $entry['reservation'])['ticket']; check6(is_array($ticket) && str_starts_with($ticket['token'], 'PNGO-'), 'verified payment issues opaque QR ticket');
    $parking = new ParkingSessionService(); $first = $parking->entry($ticket['token'], $entry['attendant']); check6($first['status'] === 'accepted', 'authorized attendant can enter with verified QR');
    try { $parking->entry($ticket['token'], $entry['attendant']); check6(false, 'duplicate entry rejected'); } catch (InvalidArgumentException) { check6(true, 'duplicate entry rejected'); }
    $exit = $parking->exit($ticket['token'], $entry['attendant']); check6($exit['status'] === 'accepted', 'exit completes active session');
    try { (new TicketService())->resolve($ticket['token']); check6(false, 'exited QR cannot be reused'); } catch (InvalidArgumentException) { check6(true, 'exited QR cannot be reused'); }

    echo "\n$checks Phase 6 deterministic checks passed. Fixtures exist only in the isolated test database.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n"); exit(1); }
