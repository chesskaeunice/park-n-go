<?php
declare(strict_types=1);

// Reconciliation queue against the loopback fake Maya (tests/run-phase7.php p7_reconciliation).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\{ReconciliationService, ReportService};
use ParkNGo\Services\Payments\PaymentService;

$pdo = Database::connection(); $queue = new ReconciliationService();
$case = static function (int $attemptId, bool $all = false) use ($queue): ?array { foreach ($queue->cases(0, $all) as $c) if ($c['key'] === 'attempt:' . $attemptId) return $c; return null; };
$attempt = static fn (int $id): array => p6_row('SELECT * FROM payment_attempts WHERE id=?', [$id]);
$checkout = static fn (): string => 'fake-' . bin2hex(random_bytes(12));
$insert = static function (array $s, string $type, string $status, ?string $checkoutId, int $amount = 2000, array $extra = []) use ($pdo): array {
    $ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12)));
    $pdo->prepare('INSERT INTO payment_attempts (reference,user_id,' . ($type === 'INITIAL_RESERVATION' ? 'reservation_id' : 'parking_session_id') . ",payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,provider_status,checkout_expires_at,failure_reason,verified_at) VALUES (?,?,?,?,?,'PHP',?,?,?,?,?,?,?)")
        ->execute([$ref, $s['driver']['id'], $type === 'INITIAL_RESERVATION' ? $s['reservation'] : $s['session'], $type, $amount, $checkoutId, $ref, $status, $status === 'verified' ? 'PAYMENT_SUCCESS' : null,
            $extra['expires'] ?? p6_utc('+10 minutes'), $extra['failure'] ?? null, $status === 'verified' ? p6_utc() : null]);
    return ['id' => (int) $pdo->lastInsertId(), 'reference' => $ref, 'checkout' => $checkoutId, 'amount' => $amount];
};
try {
    $place = p6_facility(); $admin = p7_admin(); $cookie = p6_login($admin);

    // Pending beyond its expected time: listed, then resolved only by provider evidence.
    $stale = $insert(p6_session($place), 'PARKING_EXTENSION', 'checkout_created', $id = $checkout(), 2000, ['expires' => p6_utc('-3 minutes')]);
    p7_fake_checkout($id, $stale['reference'], 2000, 'PAYMENT_PENDING');
    p6_check(($c = $case($stale['id'])) && $c['kinds'] === ['stale_pending'] && $c['can_recheck'], 'a checkout past its expiry is queued as pending beyond expected time');
    $message = $queue->act($admin['id'], 'recheck', 'attempt:' . $stale['id'], '');
    p6_check(str_contains($message, 'PAYMENT_PENDING') && $attempt($stale['id'])['local_status'] === 'pending_verification', 'a recheck that finds the payment still pending changes nothing financial');
    p7_fake_checkout($id, $stale['reference'], 2000, 'PAYMENT_EXPIRED');
    $queue->act($admin['id'], 'recheck', 'attempt:' . $stale['id'], '');
    p6_check($attempt($stale['id'])['local_status'] === 'expired' && $case($stale['id']) === null, 'a provider-expired checkout leaves the queue after recheck');
    p6_check((int) p6_value("SELECT COUNT(*) FROM reconciliation_actions WHERE case_key=? AND action='recheck'", ['attempt:' . $stale['id']]) === 2 && p7_audit('reconciliation.rechecked', $stale['id']), 'rechecks are recorded and audited');

    // Provider outage during a recheck: nothing changes.
    $outage = $insert(p6_session($place), 'PARKING_EXTENSION', 'pending_verification', $id = $checkout(), 2000, ['expires' => p6_utc('-1 minute')]);
    p7_fake_checkout($id, $outage['reference'], 2000, 'PAYMENT_SUCCESS');
    p6_fake_mode(['retrieve' => 'error503']);
    $message = $queue->act($admin['id'], 'recheck', 'attempt:' . $outage['id'], '');
    p6_fake_mode([]);
    p6_check(str_contains($message, 'unavailable') && $attempt($outage['id'])['local_status'] === 'pending_verification', 'a provider outage during recheck changes nothing');

    // Failures.
    $failed = $insert(p6_session($place), 'OVERTIME_SETTLEMENT', 'failed', null, 500, ['failure' => 'Checkout creation failed.']);
    p6_check(($c = $case($failed['id'])) && in_array('provider_error', $c['kinds'], true) && !$c['can_recheck'], 'a failed checkout creation is listed as a provider API error without a recheck');
    p6_throws(static fn () => $queue->act($admin['id'], 'recheck', 'attempt:' . $failed['id'], ''), 'a payment without a Maya reference cannot be rechecked', 'no Maya checkout reference');
    $declined = $insert(p6_session($place), 'PARKING_EXTENSION', 'failed', $checkout(), 2000, ['failure' => 'Maya reported PAYMENT_FAILED.']);
    p6_check($case($declined['id']) === null, 'an ordinary provider-declined payment is not a reconciliation case');
    $review = $insert(p6_session($place), 'PARKING_EXTENSION', 'pending_verification', $checkout(), 2000, ['failure' => 'Maya verification requires review.']);
    p6_check(in_array('manual_investigation', $case($review['id'])['kinds'], true), 'a payment whose provider data failed validation needs manual investigation');

    // Late successful payment after the checkout was swept: reconciled, never fulfilled or recognized.
    $late = p6_session($place);
    $lateAttempt = $insert($late, 'PARKING_EXTENSION', 'expired', $id = $checkout(), 2000, ['failure' => 'Checkout expired.', 'expires' => p6_utc('-20 minutes')]);
    $pdo->prepare("INSERT INTO parking_extensions (parking_session_id,payment_attempt_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until,status) VALUES (?,?,60,2000,2000,?,?,'expired')")->execute([$late['session'], $lateAttempt['id'], p6_session_row($late['session'])['paid_until'], p6_utc('+90 minutes')]);
    p7_fake_checkout($id, $lateAttempt['reference'], 2000, 'PAYMENT_SUCCESS');
    $paidUntil = p6_session_row($late['session'])['paid_until'];
    $queue->recheckReference($admin['id'], $lateAttempt['reference']);
    p6_check($attempt($lateAttempt['id'])['local_status'] === 'reconciliation' && p6_value('SELECT status FROM parking_extensions WHERE payment_attempt_id=?', [$lateAttempt['id']]) === 'reconciliation', 'a late provider success is moved to reconciliation with its ledger row');
    p6_check(p6_session_row($late['session'])['paid_until'] === $paidUntil, 'the late payment does not silently extend the session');
    p6_check(($c = $case($lateAttempt['id'])) && in_array('late_payment', $c['kinds'], true) && $c['provider_status'] === 'PAYMENT_SUCCESS', 'the late payment is listed with the provider status');
    $revenue = (new ReportService())->build('revenue', ReportService::filters([]), null);
    p6_check(!in_array($lateAttempt['reference'], array_column($revenue['rows'], 'reference'), true), 'the late payment is not counted as recognized revenue');
    $queue->act($admin['id'], 'recheck', 'attempt:' . $lateAttempt['id'], '');
    p6_check(p6_audits('payment.late_confirmation_reconciliation_required') === 1 && $attempt($lateAttempt['id'])['reconciliation_reason'] === 'Provider confirmed payment after the checkout was no longer fulfillable.', 'rechecking a reconciled payment does not re-run fulfilment or overwrite its reason');

    // Provider/local mismatch: Maya contradicts a locally verified payment; evidence is flagged, not reversed.
    $verified = $insert(p6_session($place), 'OVERTIME_SETTLEMENT', 'verified', $id = $checkout(), 750);
    $pdo->prepare("INSERT INTO parking_overtime_settlements (parking_session_id,payment_attempt_id,interval_start,interval_end,billed_seconds,rate_centavos,amount_centavos,status,verified_at) SELECT parking_session_id,id,?,?,60,750,750,'verified',UTC_TIMESTAMP() FROM payment_attempts WHERE id=?")->execute([p6_utc('-30 minutes'), p6_utc('-29 minutes'), $verified['id']]);
    p6_check($case($verified['id']) === null, 'a consistent verified payment is not queued');
    p7_fake_checkout($id, $verified['reference'], 750, 'PAYMENT_SUCCESS');
    p6_check(str_contains($queue->recheckReference($admin['id'], $verified['reference']), 'confirms') && $case($verified['id']) === null, 'a recheck that agrees with the verified payment opens no case');
    p7_fake_checkout($id, $verified['reference'], 750, 'PAYMENT_FAILED');
    $message = $queue->recheckReference($admin['id'], $verified['reference']);
    p6_check(str_contains($message, 'flagged') && $attempt($verified['id'])['local_status'] === 'verified', 'a provider contradiction is flagged without reversing the verified payment');
    p6_check(($c = $case($verified['id'])) && in_array('provider_mismatch', $c['kinds'], true), 'the provider/local mismatch is queued');
    $amountMismatch = $insert(p6_session($place), 'PARKING_EXTENSION', 'verified', $id = $checkout(), 2000);
    p7_fake_checkout($id, $amountMismatch['reference'], 1999, 'PAYMENT_SUCCESS');
    $queue->recheckReference($admin['id'], $amountMismatch['reference']);
    p6_check(str_starts_with((string) $attempt($amountMismatch['id'])['reconciliation_reason'], 'Provider/local mismatch') && in_array('provider_mismatch', $case($amountMismatch['id'])['kinds'], true), 'a provider amount that differs is flagged as a mismatch');

    // Duplicate provider notifications.
    $payments = new PaymentService();
    $dup = $insert(p6_session($place), 'PARKING_EXTENSION', 'verified', $id = $checkout(), 2000);
    $pdo->prepare("INSERT INTO parking_extensions (parking_session_id,payment_attempt_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until,status,verified_at) SELECT parking_session_id,id,60,2000,2000,?,?,'verified',UTC_TIMESTAMP() FROM payment_attempts WHERE id=?")->execute([p6_utc('-1 hour'), p6_utc(), $dup['id']]);
    $row = $attempt($dup['id']);
    $payments->handleVerifiedWebhook(p6_webhook_payload($row)); $payments->handleVerifiedWebhook(p6_webhook_payload($row));
    p6_check($case($dup['id']) === null, 'repeating the same provider callback is idempotent and opens no case');
    $payments->handleVerifiedWebhook(p6_webhook_payload($row, 'PAYMENT_SUCCESS', 'fake-' . bin2hex(random_bytes(12))));
    p6_check(($c = $case($dup['id'])) && in_array('duplicate_provider', $c['kinds'], true) && $attempt($dup['id'])['local_status'] === 'verified', 'a second provider payment for one attempt is queued as a duplicate notification');

    // Missing provider reference, failed fulfilment, and an expired hold whose checkout outcome is unknown.
    $missing = $insert(p6_session($place), 'PARKING_EXTENSION', 'verified', null, 2000);
    p6_check(($c = $case($missing['id'])) && in_array('missing_provider_reference', $c['kinds'], true) && in_array('fulfillment_failed', $c['kinds'], true), 'a verified payment without a provider reference or ledger is queued');
    $driver = p6_user('driver'); $p = $place;
    $pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$place['floor'], 'E-' . p6_uid()]); $p['slot'] = (int) $pdo->lastInsertId();
    $expiredRes = p6_reservation($driver['id'], $p, p6_utc('+1 hour'), p6_utc('+2 hours'), 'expired');
    $hold = $insert(['driver' => $driver, 'reservation' => $expiredRes], 'INITIAL_RESERVATION', 'checkout_created', $checkout(), 5000, ['expires' => p6_utc('-12 minutes')]);
    p6_check(in_array('expired_hold_unresolved', $case($hold['id'])['kinds'], true), 'an expired hold with an unresolved checkout is queued');
    $unfulfilled = $insert(['driver' => $driver, 'reservation' => $expiredRes], 'INITIAL_RESERVATION', 'verified', $checkout(), 5000);
    p6_check(in_array('fulfillment_failed', $case($unfulfilled['id'])['kinds'], true), 'a verified initial payment whose reservation was not confirmed is queued');

    // Reviewed items: recorded against the exact state, reopened when the records change.
    $c = $case($dup['id']);
    p6_throws(static fn () => $queue->act($admin['id'], 'review', $c['key'], 'Confirmed with Maya support', str_repeat('0', 64)), 'reviewing an outdated view is refused', 'changed since');
    p6_throws(static fn () => $queue->act($admin['id'], 'review', $c['key'], 'short', $c['fingerprint']), 'a review needs a reason', 'reason');
    $queue->act($admin['id'], 'note', $c['key'], 'Asked Maya support about the second payment id');
    $queue->act($admin['id'], 'review', $c['key'], 'Maya support confirmed a single capture', $c['fingerprint']);
    p6_check($case($dup['id']) === null && ($all = $case($dup['id'], true)) && $all['reviewed'] && count($all['history']) === 2, 'a reviewed case leaves the open queue and keeps its notes');
    p6_check(p7_audit('reconciliation.reviewed', $dup['id'])['reason'] === 'Maya support confirmed a single capture', 'the review is audited with its reason');
    $pdo->prepare('UPDATE payment_attempts SET reconciliation_reason=? WHERE id=?')->execute(['Another provider payment was reported for this already verified attempt (second notice).', $dup['id']]);
    p6_check($case($dup['id']) !== null, 'a reviewed case reopens when the payment records change');
    p6_throws(static fn () => $pdo->exec("UPDATE reconciliation_actions SET note='edited'"), 'reconciliation history is append-only', 'append-only');

    // HTTP: the queue renders case details and refuses any manual status manipulation.
    $page = p6_http('page=admin-reconciliation', $cookie);
    p6_check($page['status'] === 200 && str_contains($page['body'], $lateAttempt['reference']) && str_contains($page['body'], 'Late successful payment') && str_contains($page['body'], 'PAYMENT_SUCCESS') && str_contains($page['body'], '₱20.00'), 'the queue shows reference, case type, provider status, and amount');
    $before = $attempt($review['id']);
    foreach (['mark_paid', 'set_status', 'fulfil'] as $forbidden) {
        $post = p6_http('page=admin-reconciliation', $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'action' => $forbidden, 'case' => 'attempt:' . $review['id'], 'note' => 'Force this payment to verified', 'local_status' => 'verified']);
        p6_check($post['status'] === 422 && str_contains(p6_errors($post['body']), 'Unsupported'), "manual status manipulation '$forbidden' is refused");
    }
    p6_check($attempt($review['id'])['local_status'] === $before['local_status'] && $attempt($review['id'])['verified_at'] === null, 'refused manipulation left the payment unverified');
    $driverCookie = p6_login(p6_user('driver'));
    $driverPost = p6_http('page=admin-reconciliation', $driverCookie, 'POST', ['csrf' => p6_csrf(p6_http('page=profile', $driverCookie)['body']), 'action' => 'recheck', 'case' => 'attempt:' . $review['id']]);
    p6_check($driverPost['status'] === 403, 'a driver cannot trigger reconciliation actions');
    $recheck = p6_http('page=admin-reconciliation', $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'action' => 'recheck_reference', 'payment' => $verified['reference']]);
    p6_check($recheck['status'] === 303, 'an admin rechecks a payment by reference over HTTP');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 reconciliation checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
