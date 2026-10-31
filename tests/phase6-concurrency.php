<?php
declare(strict_types=1);

// Phase 6 concurrency checks using separate PHP processes and database connections (tests/run-phase6.php concurrency).
require __DIR__ . '/fixtures/phase6-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Parking\{AvailabilityWindow, ReservationService};

$pdo = Database::connection();
$barriers = BASE_PATH . '/storage/test-runtime/phase6-barriers';
if (!is_dir($barriers)) mkdir($barriers, 0700, true);
$plus = static fn(string $utc, int $minutes): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');

/** Start every job as its own process, release them together, and return their decoded results. */
function race(array $jobs): array
{
    global $barriers;
    $barrier = bin2hex(random_bytes(8)); $processes = [];
    foreach ($jobs as $index => $job) {
        $process = proc_open([PHP_BINARY, BASE_PATH . '/tests/fixtures/phase6-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, BASE_PATH);
        if (!is_resource($process)) throw new RuntimeException('Unable to start worker.');
        fwrite($pipes[0], json_encode($job + ['barrier' => $barrier, 'worker' => $index], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 15;
    while (count(glob($barriers . '/' . $barrier . '-*.ready')) < count($jobs)) { if (microtime(true) > $deadline) throw new RuntimeException('Workers did not become ready.'); usleep(5000); }
    touch($barriers . '/' . $barrier . '.go');
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $decoded = json_decode((string) $output, true);
        if (!is_array($decoded)) throw new RuntimeException('Worker produced no result: ' . trim($output . ' ' . $errors));
        if ($decoded['result'] === 'error') throw new RuntimeException('Worker error ' . $decoded['class'] . ': ' . $decoded['message']);
        $results[] = $decoded;
    }
    foreach (glob($barriers . '/' . $barrier . '*') as $file) unlink($file);
    return $results;
}
$results = static fn(array $rows, string $kind): int => count(array_filter($rows, static fn(array $row): bool => $row['result'] === $kind));

try {
    $place = p6_facility(2000, 2500);

    // 1. Two simultaneous extensions for the same session (provider slowed so both overlap checkout creation).
    p6_fake_mode(['delay_ms' => 700]);
    foreach ([[[1], [1]], [[1], [2]], [[2, 1], [3]]] as $round => [$first, $second]) {
        $s = p6_session($place); $count = p6_fake_count();
        $rows = race([['mode' => 'extend', 'user' => $s['driver']['id'], 'session' => $s['session'], 'blocks' => $first], ['mode' => 'extend', 'user' => $s['driver']['id'], 'session' => $s['session'], 'blocks' => $second]]);
        $attempts = (int) p6_value('SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=?', [$s['session']]);
        $ledgers = (int) p6_value("SELECT COUNT(*) FROM parking_extensions WHERE parking_session_id=? AND status='pending'", [$s['session']]);
        p6_check($attempts === 1 && $ledgers === 1 && p6_fake_count() === $count + 1 && $results($rows, 'url') >= 1, 'simultaneous extensions ' . json_encode($first) . ' vs ' . json_encode($second) . ' create exactly one attempt, ledger row, and Maya checkout');
    }
    p6_fake_mode([]);

    // 2. Extension racing another driver's pending reservation confirmation (live hold + protected checkout).
    for ($round = 0; $round < 3; $round++) {
        $s = p6_session($place); $paid = p6_session_row($s['session'])['paid_until']; $other = p6_user('driver');
        $pending = p6_reservation($other['id'], $s['place'], $plus($paid, 30), $plus($paid, 90), 'pending');
        p6_hold($other['id'], $s['place'], $pending, $plus($paid, 30), $plus($paid, 90), p6_utc('+8 minutes'));
        $checkout = 'fake-' . bin2hex(random_bytes(12)); $reference = 'PNGO-' . strtoupper(bin2hex(random_bytes(12)));
        file_put_contents(p6_fake_dir() . '/checkouts/' . $checkout . '.json', json_encode(['reference' => $reference, 'amount' => '50.00', 'currency' => 'PHP', 'status' => 'PAYMENT_SUCCESS']));
        $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,'INITIAL_RESERVATION',5000,'PHP',?,?,'checkout_created',?)")->execute([$reference, $other['id'], $pending, $checkout, $reference, p6_utc('+8 minutes')]);
        $rows = race([['mode' => 'extend', 'user' => $s['driver']['id'], 'session' => $s['session'], 'blocks' => [2]], ['mode' => 'poll-initial', 'user' => $other['id'], 'reference' => $reference]]);
        p6_check($rows[0]['result'] === 'conflict' && $rows[1]['status'] === 'verified' && p6_value('SELECT status FROM reservations WHERE id=?', [$pending]) === 'confirmed' && (int) p6_value('SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=?', [$s['session']]) === 0, "round $round: extension cannot take capacity held/confirmed by another driver [" . ($rows[0]['message'] ?? '') . ']');
    }

    // 3. Extension racing a brand-new reservation for the same future window: never both.
    for ($round = 0; $round < 3; $round++) {
        $s = p6_session($place); $paid = p6_session_row($s['session'])['paid_until']; $other = p6_user('driver');
        $local = (new DateTimeImmutable($plus($paid, 30), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
        $window = ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'hours' => '1'];
        $quote = (new ReservationService())->quote($s['place']['slot'], new AvailabilityWindow($window));
        $rows = race([['mode' => 'extend', 'user' => $s['driver']['id'], 'session' => $s['session'], 'blocks' => [1]], ['mode' => 'reserve', 'user' => $other['id'], 'slot' => $s['place']['slot'], 'window' => $window, 'key' => bin2hex(random_bytes(32)), 'quote_hash' => $quote['quote_hash']]]);
        $ext = p6_row("SELECT * FROM parking_extensions WHERE parking_session_id=? AND status='pending'", [$s['session']]);
        $overlap = $ext ? (int) p6_value("SELECT COUNT(*) FROM reservations WHERE slot_id=? AND id<>? AND status IN ('pending','confirmed') AND starts_at<? AND ends_at>?", [$s['place']['slot'], $s['reservation'], $ext['requested_paid_until'], $paid]) : 0;
        p6_check($rows[0]['result'] === 'url' && $rows[1]['result'] === 'conflict' && $overlap === 0, "round $round: new reservation for an occupied slot is refused while the extension proceeds (no double booking)");
    }

    // 4. Checkout expiring while its payment is verified: exactly one consistent outcome.
    $outcomes = [];
    for ($round = 0; $round < 4; $round++) {
        $s = p6_session($place); $before = p6_session_row($s['session'])['paid_until'];
        $attempt = p6_attempt_by_url((new \ParkNGo\Services\Payments\PaymentService())->startExtensionPayment($s['driver']['id'], $s['session'], [1]));
        p6_fake_status((string) $attempt['maya_checkout_id'], 'PAYMENT_SUCCESS');
        $pdo->prepare('UPDATE payment_attempts SET checkout_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=?')->execute([$attempt['id']]);
        $jobs = [['mode' => 'sweep'], ['mode' => 'poll', 'user' => $s['driver']['id'], 'reference' => $attempt['reference']]];
        race($round % 2 ? array_reverse($jobs) : $jobs);
        $final = p6_row('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']])['local_status']; $ledger = p6_ledger($attempt)['status']; $paid = p6_session_row($s['session'])['paid_until'];
        $applied = $final === 'verified' && $ledger === 'verified' && $paid === $plus($before, 60);
        $reconciled = $final === 'reconciliation' && $ledger === 'reconciliation' && $paid === $before;
        $outcomes[] = $applied ? 'applied' : ($reconciled ? 'reconciled' : "inconsistent($final/$ledger)");
        p6_check($applied xor $reconciled, "round $round: expiry during verification is either applied once or reconciled, never half-applied ({$outcomes[$round]})");
    }

    // 5. Two payment callbacks fulfilling simultaneously (webhook + poll, and duplicate webhooks).
    for ($round = 0; $round < 3; $round++) {
        $s = p6_session($place); $before = p6_session_row($s['session'])['paid_until']; $reconciliations = p6_audits('payment.extension_reconciliation_required');
        $attempt = p6_attempt_by_url((new \ParkNGo\Services\Payments\PaymentService())->startExtensionPayment($s['driver']['id'], $s['session'], [2]));
        p6_fake_status((string) $attempt['maya_checkout_id'], 'PAYMENT_SUCCESS');
        $webhook = ['mode' => 'webhook', 'payload' => p6_webhook_payload($attempt)];
        race([$webhook, ['mode' => 'poll', 'user' => $s['driver']['id'], 'reference' => $attempt['reference']], $webhook]);
        $events = (int) p6_value('SELECT COUNT(*) FROM payment_attempt_events WHERE payment_attempt_id=? AND provider_event_id=?', [$attempt['id'], $attempt['maya_checkout_id']]);
        p6_check(p6_session_row($s['session'])['paid_until'] === $plus($before, 120) && p6_ledger($attempt)['status'] === 'verified' && $events === 1 && p6_audits('payment.extension_reconciliation_required') === $reconciliations, "round $round: concurrent extension callbacks extend paid-until exactly once");
    }
    for ($round = 0; $round < 3; $round++) {
        $s = p6_session($place, null, '-20 minutes', '-80 minutes');
        $attempt = p6_attempt_by_url((new \ParkNGo\Services\Payments\PaymentService())->startOvertimeSettlement($s['driver']['id'], $s['session']));
        p6_fake_status((string) $attempt['maya_checkout_id'], 'PAYMENT_SUCCESS');
        $webhook = ['mode' => 'webhook', 'payload' => p6_webhook_payload($attempt)];
        race([$webhook, ['mode' => 'poll', 'user' => $s['driver']['id'], 'reference' => $attempt['reference']], $webhook]);
        $settled = p6_ledger($attempt);
        $verifiedAudits = (int) p6_value("SELECT COUNT(*) FROM audit_logs WHERE action='payment.verified_overtime_settlement' AND subject_id=?", [$attempt['id']]);
        p6_check($settled['status'] === 'verified' && $settled['grace_until'] === $plus($settled['verified_at'], 15) && $verifiedAudits === 1, "round $round: concurrent overtime callbacks verify once and start one 15-minute grace");
    }

    // 6. Concurrent overtime checkout and extension attempts never produce two session charges.
    for ($round = 0; $round < 2; $round++) {
        $s = p6_session($place, null, '-10 minutes', '-70 minutes');
        race([['mode' => 'overtime', 'user' => $s['driver']['id'], 'session' => $s['session']], ['mode' => 'extend', 'user' => $s['driver']['id'], 'session' => $s['session'], 'blocks' => [1]], ['mode' => 'overtime', 'user' => $s['driver']['id'], 'session' => $s['session']]]);
        $live = (int) p6_value("SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=? AND local_status IN ('created','checkout_created','pending_verification')", [$s['session']]);
        p6_check($live === 1 && p6_latest_attempt($s['session'])['payment_type'] === 'OVERTIME_SETTLEMENT', "round $round: overdue session gets one overtime checkout and no extension");
    }

    echo "\n{$GLOBALS['p6_checks']} Phase 6 concurrency checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n"); exit(1); }
