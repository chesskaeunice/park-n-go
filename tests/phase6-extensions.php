<?php
declare(strict_types=1);

// Phase 6 extension and session-payment service checks against the loopback fake Maya (tests/run-phase6.php extensions).
require __DIR__ . '/fixtures/phase6-support.php';

use ParkNGo\Services\{Clock, Database};
use ParkNGo\Services\Parking\OvertimeService;
use ParkNGo\Services\Payments\{MayaApiException, PaymentService};
use ParkNGo\Services\Qr\TicketService;

$pdo = Database::connection();
$payments = new PaymentService();
$plus = static fn(string $utc, int $minutes): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes')->format('Y-m-d H:i:s');
$extend = static function (array $s, array $blocks) use ($payments): array {
    $url = $payments->startExtensionPayment($s['driver']['id'], $s['session'], $blocks);
    $attempt = p6_attempt_by_url($url);
    return [$url, $attempt, p6_ledger($attempt)];
};
$pay = static function (array $attempt, string $status) use ($payments): array {
    p6_fake_status((string) $attempt['maya_checkout_id'], $status);
    return $payments->verifyPayment((int) $attempt['user_id'], $attempt['reference']);
};

try {
    $place = p6_facility(2000, 2500);

    // --- Single blocks: server price, verified fulfilment, same session and QR ---
    foreach ([1 => 2000, 2 => 4000, 3 => 6000] as $hours => $price) {
        $s = p6_session($place); $before = p6_session_row($s['session']); $ticketBefore = p6_row('SELECT id,token_hash,manual_code_hash FROM qr_tickets WHERE id=?', [$s['ticket']]);
        $count = p6_fake_count();
        [$url, $attempt, $ledger] = $extend($s, [$hours]);
        $checkout = p6_fake_checkout((string) $attempt['maya_checkout_id']);
        p6_check(str_starts_with($url, 'https://') && $attempt['local_status'] === 'checkout_created' && p6_fake_count() === $count + 1, "$hours-hour extension creates exactly one Maya checkout");
        p6_check((int) $ledger['duration_minutes'] === $hours * 60 && (int) $ledger['amount_centavos'] === $price && (int) $attempt['amount_centavos'] === $price && $checkout['amount'] === number_format($price / 100, 2, '.', '') && (int) $ledger['rate_centavos'] === 2000, "$hours-hour extension is priced server-side at the facility hourly rate (" . $checkout['amount'] . ')');
        p6_check($ledger['paid_until_before'] === $before['paid_until'] && $ledger['requested_paid_until'] === $plus($before['paid_until'], $hours * 60) && p6_session_row($s['session'])['paid_until'] === $before['paid_until'], "$hours-hour extension is contiguous and paid-until is unchanged before verification");
        $result = $pay($attempt, 'PAYMENT_PENDING');
        p6_check($result['local_status'] === 'pending_verification' && p6_ledger($attempt)['status'] === 'pending' && p6_session_row($s['session'])['paid_until'] === $before['paid_until'], "$hours-hour pending provider status does not extend");
        $result = $pay($attempt, 'PAYMENT_SUCCESS');
        $after = p6_session_row($s['session']); $ticketAfter = p6_row('SELECT id,token_hash,manual_code_hash FROM qr_tickets WHERE id=?', [$s['ticket']]);
        p6_check($result['local_status'] === 'verified' && p6_ledger($attempt)['status'] === 'verified' && $after['paid_until'] === $plus($before['paid_until'], $hours * 60), "$hours-hour verified payment extends paid-until exactly once");
        p6_check((int) $after['id'] === $s['session'] && $after['status'] === 'active' && $ticketAfter === $ticketBefore && (int) (new TicketService())->resolve($s['token'], true)['id'] === $s['ticket'], "$hours-hour extension preserves the same parking session and QR ticket");
    }

    // --- Combinations totaling up to six hours ---
    foreach ([[1, 2], [1, 3], [2, 3], [1, 2, 3], [3, 3], [2, 2, 2], [1, 1], ['2', '1']] as $blocks) {
        $hours = array_sum(array_map('intval', $blocks)); $s = p6_session($place); $before = p6_session_row($s['session'])['paid_until'];
        [, $attempt, $ledger] = $extend($s, $blocks);
        $pay($attempt, 'PAYMENT_SUCCESS');
        p6_check((int) $ledger['duration_minutes'] === $hours * 60 && (int) $ledger['amount_centavos'] === 2000 * $hours && p6_session_row($s['session'])['paid_until'] === $plus($before, $hours * 60), 'combination [' . implode('+', $blocks) . "] totals {$hours}h and is fulfilled once");
    }

    // --- Invalid or excessive durations never create a payment attempt ---
    $s = p6_session($place);
    foreach ([[4], [0], [-1], ['1.5'], ['abc'], [], [3, 3, 1], [1, 2, 3, 1], [[1]], [6]] as $blocks) {
        p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], $blocks), 'invalid extension ' . json_encode($blocks) . ' is rejected', '', InvalidArgumentException::class);
    }
    p6_check((int) p6_value('SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=?', [$s['session']]) === 0, 'rejected durations created no payment attempt or ledger row');
    p6_throws(fn() => $payments->startExtensionPayment(p6_user('driver')['id'], $s['session'], [1]), 'another driver cannot extend this session', '', OutOfBoundsException::class);

    // --- Correct facility rate: each facility uses its own rate; a missing rate is refused ---
    $other = p6_facility(3150, 4100); $s = p6_session($other);
    [, $attempt, $ledger] = $extend($s, [2]);
    p6_check((int) $ledger['amount_centavos'] === 6300 && (int) $ledger['rate_centavos'] === 3150, 'extension uses the session facility rate (31.50/h x 2 = 63.00)');
    $s = p6_session($other, null, '-30 minutes', '-90 minutes');
    $state = (new OvertimeService())->calculate(p6_row('SELECT p.*,pr.overtime_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE p.id=?', [$s['session']]), null, $pdo);
    p6_check($state['rate_centavos'] === 4100, 'overtime uses the session facility overtime rate');
    $free = p6_facility(0, 2500); $s = p6_session($free);
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]), 'facility without an hourly rate cannot sell extensions', 'no extension rate');

    // --- Failed, cancelled, and expired provider outcomes; safe retry ---
    foreach (['PAYMENT_FAILED' => 'failed', 'PAYMENT_CANCELLED' => 'cancelled', 'PAYMENT_EXPIRED' => 'expired'] as $provider => $local) {
        $s = p6_session($place); $before = p6_session_row($s['session'])['paid_until'];
        [, $attempt] = $extend($s, [1]);
        $result = $pay($attempt, $provider);
        p6_check($result['local_status'] === $local && p6_ledger($attempt)['status'] === $local && p6_session_row($s['session'])['paid_until'] === $before, strtolower($provider) . ' leaves paid-until unchanged and closes the ledger');
        [, $retry] = $extend($s, [1]);
        p6_check($retry['reference'] !== $attempt['reference'] && $retry['local_status'] === 'checkout_created', strtolower($provider) . ' permits a fresh retry checkout');
        if ($provider === 'PAYMENT_FAILED') {
            $before2 = p6_audits('payment.late_confirmation_reconciliation_required');
            $payments->handleVerifiedWebhook(p6_webhook_payload($attempt));
            $late = p6_row('SELECT * FROM payment_attempts WHERE id=?', [$attempt['id']]);
            p6_check($late['local_status'] === 'reconciliation' && p6_ledger($attempt)['status'] === 'reconciliation' && p6_session_row($s['session'])['paid_until'] === $before && p6_audits('payment.late_confirmation_reconciliation_required') === $before2 + 1, 'late success after a failed checkout enters reconciliation without extending');
        }
    }

    // --- Duplicate notifications and simultaneous callback kinds (webhook + poll) ---
    $s = p6_session($place); $before = p6_session_row($s['session'])['paid_until'];
    [, $attempt] = $extend($s, [2]);
    $reconciliations = p6_audits('payment.extension_reconciliation_required');
    $payments->handleVerifiedWebhook(p6_webhook_payload($attempt));
    $payments->handleVerifiedWebhook(p6_webhook_payload($attempt));
    p6_fake_status((string) $attempt['maya_checkout_id'], 'PAYMENT_SUCCESS'); $payments->verifyPayment($s['driver']['id'], $attempt['reference']);
    $events = (int) p6_value('SELECT COUNT(*) FROM payment_attempt_events WHERE payment_attempt_id=?', [$attempt['id']]);
    p6_check(p6_session_row($s['session'])['paid_until'] === $plus($before, 120) && $events === 1, 'duplicate webhook is deduplicated and an already-verified attempt is not re-applied (events=' . $events . ')');
    p6_check(p6_audits('payment.extension_reconciliation_required') === $reconciliations, 'duplicate callbacks do not raise false reconciliation');
    $flags = p6_audits('payment.duplicate_provider_payment_review');
    $payments->handleVerifiedWebhook(p6_webhook_payload($attempt, 'PAYMENT_SUCCESS', 'fake-' . str_repeat('0', 24)));
    p6_check(p6_audits('payment.duplicate_provider_payment_review') === $flags + 1 && p6_session_row($s['session'])['paid_until'] === $plus($before, 120), 'a second provider payment for a verified attempt is flagged for review, never re-applied');

    // --- Expired pending checkout: sweep, late success, and retry ---
    $s = p6_session($place); $before = p6_session_row($s['session'])['paid_until'];
    [, $attempt] = $extend($s, [1]);
    $pdo->prepare('UPDATE payment_attempts SET checkout_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=?')->execute([$attempt['id']]);
    p6_check($payments->expireSessionCheckouts() >= 1 && p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'expired' && p6_ledger($attempt)['status'] === 'expired', 'lapsed extension checkout expires together with its ledger row');
    $payments->handleVerifiedWebhook(p6_webhook_payload($attempt));
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'reconciliation' && p6_ledger($attempt)['status'] === 'reconciliation' && p6_session_row($s['session'])['paid_until'] === $before, 'late success for an expired extension checkout enters reconciliation');
    [, $attempt] = $extend($s, [1]);
    $pdo->prepare('UPDATE payment_attempts SET checkout_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=?')->execute([$attempt['id']]);
    [, $fresh] = $extend($s, [2]);
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'expired' && p6_ledger($attempt)['status'] === 'expired' && (int) p6_ledger($fresh)['duration_minutes'] === 120, 'starting a new checkout sweeps the lapsed one consistently before creating another');
    $pay($attempt, 'PAYMENT_PENDING');
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'expired' && p6_ledger($attempt)['status'] === 'expired', 'a pending provider status never revives a swept checkout');
    $pay($attempt, 'PAYMENT_SUCCESS');
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'reconciliation' && p6_ledger($attempt)['status'] === 'reconciliation' && p6_session_row($s['session'])['paid_until'] === $before, 'polling a swept checkout detects a late provider success and reconciles it without extending');

    // --- Pending checkout reuse and duration mismatch ---
    $s = p6_session($place); $count = p6_fake_count();
    [$url] = $extend($s, [1]);
    p6_check($payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]) === $url && p6_fake_count() === $count + 1, 'repeating the same extension reuses the pending Maya checkout');
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [2]), 'a different duration is refused while a checkout is pending', '1-hour extension checkout is already pending');

    // --- Maya client initialization and provider failures (Fix B) ---
    foreach (['disabled' => fn() => p6_maya_enabled(false), 'missing credentials' => function () { putenv('MAYA_ENABLED=true'); putenv('MAYA_PUBLIC_KEY='); }, 'provider HTTP 500' => function () { p6_maya_enabled(true); p6_fake_mode(['checkout' => 'error500']); }] as $label => $setup) {
        foreach (['PARKING_EXTENSION', 'OVERTIME_SETTLEMENT'] as $type) {
            $s = $type === 'PARKING_EXTENSION' ? p6_session($place) : p6_session($place, null, '-20 minutes', '-80 minutes');
            $setup(); $message = '';
            try { $type === 'PARKING_EXTENSION' ? $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]) : $payments->startOvertimeSettlement($s['driver']['id'], $s['session']); }
            catch (RuntimeException $error) { $message = $error->getMessage(); }
            $attempt = p6_latest_attempt($s['session']); $ledger = p6_ledger($attempt);
            $secret = (string) getenv('FAKE_MAYA_SECRET_KEY');
            p6_check($message !== '' && !str_contains($message, $secret) && !str_contains($message, (string) getenv('FAKE_MAYA_PUBLIC_KEY')), "$label ($type) raises a generic error without credentials");
            p6_check($attempt['local_status'] === 'failed' && $attempt['failure_reason'] === 'Checkout creation failed.' && $attempt['maya_checkout_id'] === null && $ledger['status'] === 'failed', "$label ($type) marks the attempt and ledger failed");
            p6_maya_enabled(true); p6_fake_mode([]);
            $retry = $type === 'PARKING_EXTENSION' ? $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]) : $payments->startOvertimeSettlement($s['driver']['id'], $s['session']);
            $retryAttempt = p6_attempt_by_url($retry);
            p6_check((int) $retryAttempt['id'] !== (int) $attempt['id'] && $retryAttempt['local_status'] === 'checkout_created' && p6_ledger($retryAttempt)['status'] === 'pending', "$label ($type) retry creates one fresh checkout");
        }
    }
    p6_check(!str_contains((string) @file_get_contents(BASE_PATH . '/storage/logs/app.log'), (string) getenv('FAKE_MAYA_SECRET_KEY')), 'Maya credentials never reach the application log');

    // --- In-flight and orphaned checkout creation never produce a second Maya checkout ---
    $s = p6_session($place);
    $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,'PARKING_EXTENSION',2000,'PHP',?,'created',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))")->execute([$ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))), $s['driver']['id'], $s['session'], $ref]);
    $orphan = (int) $pdo->lastInsertId(); $paid = p6_session_row($s['session'])['paid_until'];
    $pdo->prepare('INSERT INTO parking_extensions (parking_session_id,payment_attempt_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until) VALUES (?,?,60,2000,2000,?,?)')->execute([$s['session'], $orphan, $paid, $plus($paid, 60)]);
    $count = p6_fake_count();
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]), 'an in-flight checkout blocks a concurrent second checkout', 'already being prepared');
    $pdo->prepare('UPDATE payment_attempts SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE id=?')->execute([$orphan]);
    [, $fresh] = $extend($s, [1]);
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$orphan]) === 'failed' && p6_value('SELECT status FROM parking_extensions WHERE payment_attempt_id=?', [$orphan]) === 'failed' && p6_fake_count() === $count + 1 && (int) $fresh['id'] !== $orphan, 'an orphaned created attempt is released and exactly one new checkout is created');

    // --- Overdue sessions must settle overtime first (Fix A), including a checkout created before going overdue ---
    $s = p6_session($place, null, '-5 minutes', '-65 minutes');
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]), 'overdue session cannot start an extension', 'Settle verified overtime');
    p6_check((int) p6_value('SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=?', [$s['session']]) === 0, 'blocked overdue extension created no attempt');
    $payments->startOvertimeSettlement($s['driver']['id'], $s['session']);
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]), 'pending overtime checkout also blocks extension', 'overtime checkout is still pending');
    $s = p6_session($place, null, '+2 minutes', '-58 minutes'); $before = p6_session_row($s['session'])['paid_until'];
    [, $pendingExtension] = $extend($s, [1]);
    Clock::freeze(p6_utc('+10 minutes'));
    p6_throws(fn() => $payments->startOvertimeSettlement($s['driver']['id'], $s['session']), 'a pending extension prevents a duplicate overtime charge for the same interval', 'will also cover this overtime');
    Clock::freeze(null);
    $pay($pendingExtension, 'PAYMENT_SUCCESS');
    p6_check(p6_session_row($s['session'])['paid_until'] === $plus($before, 60), 'the pending contiguous extension, once verified, covers the elapsed overdue time');

    // --- Approved time base: an extension during settlement grace starts now and supersedes the grace ---
    $s = p6_session($place, null, '-40 minutes', '-100 minutes'); $oldPaid = p6_session_row($s['session'])['paid_until'];
    $settlement = p6_attempt_by_url($payments->startOvertimeSettlement($s['driver']['id'], $s['session']));
    $pay($settlement, 'PAYMENT_SUCCESS');
    $grace = p6_ledger($settlement);
    $now = p6_utc(); [, $attempt, $ledger] = $extend($s, [1]);
    p6_check($ledger['paid_until_before'] === $oldPaid && abs(strtotime($ledger['requested_paid_until']) - strtotime($plus($now, 60))) <= 2 && $ledger['requested_paid_until'] > $grace['interval_end'], 'extension in grace starts at server time, not at the settled paid-until (no re-billing of settled overtime)');
    $pay($attempt, 'PAYMENT_SUCCESS');
    $row = p6_row('SELECT p.*,pr.overtime_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE p.id=?', [$s['session']]);
    $state = (new OvertimeService())->calculate($row, $plus($grace['grace_until'], 5), $pdo);
    p6_check($row['paid_until'] === $ledger['requested_paid_until'] && $row['paid_until'] > $grace['grace_until'] && !$state['payment_required'], 'verified extension supersedes the remaining settlement grace');

    // --- Unfulfillable late payments enter reconciliation instead of extending ---
    $s = p6_session($place); [, $attempt] = $extend($s, [1]);
    $pdo->prepare("UPDATE parking_sessions SET status='completed',exit_at=UTC_TIMESTAMP() WHERE id=?")->execute([$s['session']]);
    $pay($attempt, 'PAYMENT_SUCCESS');
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'reconciliation' && p6_ledger($attempt)['status'] === 'reconciliation', 'payment after exit enters reconciliation');
    $s = p6_session($place); [, $attempt] = $extend($s, [1]); $before = p6_session_row($s['session'])['paid_until'];
    $pdo->prepare('UPDATE parking_sessions SET paid_until=DATE_ADD(paid_until, INTERVAL 5 MINUTE) WHERE id=?')->execute([$s['session']]);
    $pay($attempt, 'PAYMENT_SUCCESS');
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'reconciliation' && p6_session_row($s['session'])['paid_until'] === $plus($before, 5), 'payment against a changed paid-until enters reconciliation (no stacking)');

    // --- Future capacity: confirmed bookings, live holds, protected checkouts (0-minute buffer) ---
    $s = p6_session($place); $paid = p6_session_row($s['session'])['paid_until']; $other = p6_user('driver');
    $future = p6_reservation($other['id'], $s['place'], $plus($paid, 60), $plus($paid, 120));
    [, $attempt] = $extend($s, [1]);
    p6_check($attempt['local_status'] === 'checkout_created', 'extension ending exactly when a confirmed booking starts is allowed (0-minute buffer)');
    $pay($attempt, 'PAYMENT_FAILED');
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [2]), 'extension overlapping a confirmed future reservation is refused', 'reserved by another driver');
    $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$future]);
    $held = p6_reservation($other['id'], $s['place'], $plus($paid, 30), $plus($paid, 90), 'pending');
    p6_hold($other['id'], $s['place'], $held, $plus($paid, 30), $plus($paid, 90), p6_utc('+8 minutes'));
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]), 'extension overlapping an active hold is refused', 'holding this space');
    $pdo->prepare('UPDATE reservation_holds SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE reservation_id=?')->execute([$held]);
    $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,'INITIAL_RESERVATION',5000,'PHP',?,?,'pending_verification',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))")->execute([$ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))), $other['id'], $held, 'chk-' . p6_uid(), $ref]);
    p6_throws(fn() => $payments->startExtensionPayment($s['driver']['id'], $s['session'], [1]), 'extension overlapping another protected pending checkout is refused', 'completing payment');
    $pdo->prepare("UPDATE payment_attempts SET local_status='expired' WHERE reference=?")->execute([$ref]);
    $elsewhere = p6_facility(); p6_reservation($other['id'], $elsewhere, $plus($paid, 0), $plus($paid, 300));
    [, $attempt] = $extend($s, [3]);
    p6_check($attempt['local_status'] === 'checkout_created', 'expired holds, cancelled bookings, and other slots do not block extensions');
    p6_reservation($other['id'], $s['place'], $plus($paid, 150), $plus($paid, 200));
    $pay($attempt, 'PAYMENT_SUCCESS');
    p6_check(p6_value('SELECT local_status FROM payment_attempts WHERE id=?', [$attempt['id']]) === 'reconciliation' && p6_session_row($s['session'])['paid_until'] === $paid, 'capacity is re-checked at fulfilment; a conflicting booking sends the payment to reconciliation');

    echo "\n{$GLOBALS['p6_checks']} Phase 6 extension checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n"); exit(1); }
