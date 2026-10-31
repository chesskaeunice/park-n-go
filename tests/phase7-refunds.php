<?php
declare(strict_types=1);

// Refund request, approval, and provider-confirmation lifecycle against the fake Maya (tests/run-phase7.php p7_refunds).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\{PermissionDenied, RefundService, ReportService};

$pdo = Database::connection(); $refunds = new RefundService();
$key = static fn (): string => bin2hex(random_bytes(32));
$status = static fn (int $id): string => (string) p6_value('SELECT status FROM refund_requests WHERE id=?', [$id]);
$remaining = static fn (string $reference): int => (new RefundService())->payment($reference)['refundable_centavos'];
try {
    $place = p6_facility(); $requester = p7_admin(); $approver = p7_admin(['approve_refunds']); $plain = p7_admin();
    $payment = p7_payment($place, 'INITIAL_RESERVATION', 5000, p6_utc());

    // Requests must reference a provider-verified payment, in valid centavo amounts, with a reason.
    $pending = p7_payment($place, 'PARKING_EXTENSION', 2000, p6_utc(), 'checkout_created', null);
    p6_throws(static fn () => $refunds->request($requester['id'], $pending['reference'], '10', 'Driver asked for a refund of a pending payment', $key()), 'a pending payment cannot be refunded', 'provider-verified');
    $failed = p7_payment($place, 'PARKING_EXTENSION', 2000, p6_utc(), 'failed', 'PAYMENT_FAILED');
    p6_throws(static fn () => $refunds->request($requester['id'], $failed['reference'], '10', 'Driver asked for a refund of a failed payment', $key()), 'a failed payment cannot be refunded', 'provider-verified');
    p6_throws(static fn () => $refunds->request($requester['id'], 'PNGO-' . str_repeat('A', 24), '10', 'Refund for a payment that does not exist', $key()), 'an unknown payment cannot be refunded', 'not found', OutOfBoundsException::class);
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '50.01', 'Requested more than the driver paid', $key()), 'a refund larger than the payment is rejected', 'remaining refundable balance');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '-5', 'Negative refund amount entered', $key()), 'a negative refund amount is rejected', 'pesos');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '0', 'Zero refund amount entered by mistake', $key()), 'a zero refund amount is rejected', 'between');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '10.005', 'Sub-centavo refund precision entered', $key()), 'sub-centavo precision is rejected', 'two decimal places');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '10', 'short', $key()), 'a refund request needs a reason', 'reason');

    $form = $key();
    $first = $refunds->request($requester['id'], $payment['reference'], '20.00', 'Gate barrier fault charged the driver twice', $form);
    p6_check($first['status'] === 'requested' && (int) $first['amount_centavos'] === 2000 && !$first['duplicate'], 'a refund is requested against a verified payment');
    p6_check(($event = p7_audit('refund.requested', (int) $first['id'])) && (int) $event['actor_id'] === $requester['id'] && $event['reason'] === 'Gate barrier fault charged the driver twice', 'the request is audited with its reason');
    p6_check($refunds->request($requester['id'], $payment['reference'], '20.00', 'Gate barrier fault charged the driver twice', $form)['duplicate'] && (int) p6_value('SELECT COUNT(*) FROM refund_requests WHERE payment_attempt_id=?', [$payment['id']]) === 1, 'resubmitting the same form returns the existing request');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '5.00', 'A second refund while one is open', $key()), 'a second open request for the same payment is refused', 'still open');
    p6_check($remaining($payment['reference']) === 5000, 'an undecided request does not reserve the refundable balance yet');

    // Review and decisions need approve_refunds.
    p6_throws(static fn () => $refunds->startReview($plain['id'], (int) $first['id']), 'an admin without approve_refunds cannot start a review', '', PermissionDenied::class);
    p6_throws(static fn () => $refunds->decide($requester['id'], (int) $first['id'], true, 'Approving my own request'), 'an admin without approve_refunds cannot approve', '', PermissionDenied::class);
    p6_check($refunds->startReview($approver['id'], (int) $first['id'])['status'] === 'under_review', 'an approver moves the request under review');
    p6_throws(static fn () => $refunds->decide($approver['id'], (int) $first['id'], true, 'ok'), 'a decision needs a reason', 'reason');
    $approved = $refunds->decide($approver['id'], (int) $first['id'], true, 'CCTV confirms the duplicate gate charge');
    p6_check($approved['status'] === 'approved' && (int) $approved['decided_by'] === $approver['id'], 'an approver approves the refund');
    p6_check(RefundService::LABELS['approved'] === 'Approved — provider refund pending' && $approved['provider_confirmed_at'] === null, 'approval is labeled provider-pending and is not a completed refund');
    p6_check($remaining($payment['reference']) === 3000, 'approval reserves the approved amount');
    p6_throws(static fn () => $refunds->decide($approver['id'], (int) $first['id'], false, 'Trying to decide twice'), 'a decided refund cannot be decided again', 'already been decided');
    $report = (new ReportService())->build('revenue', ReportService::filters([]), null);
    p6_check(array_column($report['summary'], 1, 0)['Provider-confirmed refunds'] === 0, 'an approved refund is not subtracted from revenue');

    // Provider evidence: record the Maya refund id, then only a matching Maya success confirms it.
    p6_throws(static fn () => $refunds->recordProviderRefund($approver['id'], (int) $first['id'], 'bad id with spaces'), 'an invalid Maya refund identifier is rejected', 'Maya refund identifier');
    p6_throws(static fn () => $refunds->recordProviderRefund($plain['id'], (int) $first['id'], 'rfd-' . bin2hex(random_bytes(6))), 'recording provider evidence needs approve_refunds', '', PermissionDenied::class);
    $providerId = 'rfd-' . bin2hex(random_bytes(8));
    p6_check($refunds->recordProviderRefund($approver['id'], (int) $first['id'], $providerId)['status'] === 'provider_submitted', 'the Maya refund id is recorded as provider-submitted');
    p6_check(str_contains($refunds->checkProvider($approver['id'], (int) $first['id'])['message'], 'awaiting confirmation') && $status((int) $first['id']) === 'provider_submitted', 'a refund Maya cannot find yet stays awaiting confirmation');
    p7_fake_refund($payment['checkout'], $providerId, 2000, 'PENDING');
    p6_check(str_contains($refunds->checkProvider($approver['id'], (int) $first['id'])['message'], 'PENDING') && $status((int) $first['id']) === 'provider_submitted', 'a provider-pending refund is not confirmed');
    p6_fake_mode(['refund' => 'error503']);
    p6_check(str_contains($refunds->checkProvider($approver['id'], (int) $first['id'])['message'], 'unavailable') && $status((int) $first['id']) === 'provider_submitted', 'a provider outage leaves the refund awaiting confirmation');
    p6_fake_mode([]);
    p6_throws(static fn () => $refunds->checkProvider($plain['id'], (int) $first['id']), 'checking provider evidence needs approve_refunds', '', PermissionDenied::class);
    p7_fake_refund($payment['checkout'], $providerId, 2000, 'SUCCESS');
    $confirmed = $refunds->checkProvider($approver['id'], (int) $first['id']);
    p6_check($confirmed['status'] === 'provider_confirmed' && $confirmed['provider_confirmed_at'] !== null && $confirmed['provider_status'] === 'SUCCESS', 'a matching Maya success confirms the refund');
    $checks = (int) p6_value("SELECT COUNT(*) FROM admin_audit_events WHERE action='refund.provider_checked' AND target_id=?", [$first['id']]);
    $again = $refunds->checkProvider($approver['id'], (int) $first['id']);
    p6_check(str_contains($again['message'], 'Already confirmed') && (int) p6_value("SELECT COUNT(*) FROM admin_audit_events WHERE action='refund.provider_checked' AND target_id=?", [$first['id']]) === $checks && $again['provider_confirmed_at'] === $confirmed['provider_confirmed_at'], 'a duplicate provider confirmation changes nothing');
    p6_throws(static fn () => $pdo->prepare("UPDATE refund_requests SET status='provider_confirmed',provider_refund_id=NULL WHERE id=?")->execute([$first['id']]), 'the schema refuses a confirmed refund without provider evidence', 'chk_refund_confirmed');

    // Partial refunds and over-refund prevention across requests.
    p6_check($remaining($payment['reference']) === 3000, 'the remaining refundable balance reflects the confirmed refund');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '30.01', 'Second partial refund too large', $key()), 'a later partial refund cannot exceed the remaining balance', 'remaining refundable balance');
    $second = $refunds->request($requester['id'], $payment['reference'], '30.00', 'Remaining balance after gate review', $key());
    $refunds->decide($approver['id'], (int) $second['id'], true, 'Facility manager authorized full refund');
    $secondProvider = 'rfd-' . bin2hex(random_bytes(8));
    p6_throws(static fn () => $refunds->recordProviderRefund($approver['id'], (int) $second['id'], $providerId), 'one Maya refund cannot be linked to two requests', 'already linked');
    $refunds->recordProviderRefund($approver['id'], (int) $second['id'], $secondProvider);
    p7_fake_refund($payment['checkout'], $secondProvider, 2999, 'SUCCESS');
    p6_check($refunds->checkProvider($approver['id'], (int) $second['id'])['status'] === 'reconciliation', 'a Maya refund amount that differs from the approval needs reconciliation');
    p6_check($remaining($payment['reference']) === 0, 'an amount under reconciliation stays reserved');
    p6_throws(static fn () => $refunds->request($requester['id'], $payment['reference'], '0.01', 'Any further refund', $key()), 'a fully reserved payment accepts no further refund', 'remaining refundable balance');

    // Rejection and provider failure release the balance.
    $other = p7_payment($place, 'OVERTIME_SETTLEMENT', 1200, p6_utc());
    $rejected = $refunds->request($requester['id'], $other['reference'], '12.00', 'Driver disputes overtime charge', $key());
    p6_check($refunds->decide($approver['id'], (int) $rejected['id'], false, 'Gate logs show the driver overstayed')['status'] === 'rejected' && $remaining($other['reference']) === 1200, 'a rejected refund reserves nothing');
    $retry = $refunds->request($requester['id'], $other['reference'], '6.00', 'Partial goodwill refund approved by supervisor', $key());
    $refunds->decide($approver['id'], (int) $retry['id'], true, 'Supervisor approved partial goodwill');
    $failedProvider = 'rfd-' . bin2hex(random_bytes(8));
    $refunds->recordProviderRefund($approver['id'], (int) $retry['id'], $failedProvider);
    p7_fake_refund($other['checkout'], $failedProvider, 600, 'FAILED');
    p6_check($refunds->checkProvider($approver['id'], (int) $retry['id'])['status'] === 'provider_failed' && $remaining($other['reference']) === 1200, 'a provider-failed refund is recorded and releases the reserved amount');

    // Late, unfulfillable payments can be refunded only through this authorized workflow.
    $late = p7_payment($place, 'PARKING_EXTENSION', 2000, p6_utc(), 'reconciliation', 'PAYMENT_SUCCESS');
    p6_check($refunds->payment($late['reference'])['refundable_centavos'] === 2000 && (int) p6_value('SELECT COUNT(*) FROM refund_requests WHERE payment_attempt_id=?', [$late['id']]) === 0, 'a provider-paid late payment is refundable but never refunded automatically');

    // Data integrity: approval re-checks the total even if another reservation of the balance appeared.
    $guard = p7_payment($place, 'INITIAL_RESERVATION', 1000, p6_utc());
    $open = $refunds->request($requester['id'], $guard['reference'], '8.00', 'Guard test for concurrent reservation', $key());
    $pdo->prepare("INSERT INTO refund_requests (reference,payment_attempt_id,amount_centavos,status,request_reason,requested_by,decided_by,decided_at,idempotency_key) VALUES (?,?,500,'approved','Imported legacy approval',?,?,UTC_TIMESTAMP(),?)")->execute(['RFD-' . strtoupper(bin2hex(random_bytes(10))), $guard['id'], $requester['id'], $approver['id'], $key()]);
    p6_throws(static fn () => $refunds->decide($approver['id'], (int) $open['id'], true, 'Approve despite the legacy approval'), 'approval refuses to exceed the verified payment', 'exceed the verified payment');

    // Audit history and revenue effect.
    $actions = $pdo->prepare("SELECT action FROM admin_audit_events WHERE target_type='refund_request' AND target_id=? ORDER BY id"); $actions->execute([$first['id']]);
    p6_check(array_values(array_unique($actions->fetchAll(PDO::FETCH_COLUMN))) === ['refund.requested', 'refund.review_started', 'refund.approved', 'refund.provider_reference_recorded', 'refund.provider_checked'], 'the refund audit history records every lifecycle step in order');
    $report = (new ReportService())->build('revenue', ReportService::filters([]), null);
    $summary = array_column($report['summary'], 1, 0);
    p6_check($summary['Provider-confirmed refunds'] === 2000 && $summary['Net verified receipts'] === $summary['Gross verified receipts'] - 2000, 'only the provider-confirmed refund is subtracted from net receipts');

    // HTTP: lifecycle labels and permission enforcement on the page.
    $cookie = p6_login($approver);
    $page = p6_http('page=admin-refunds&status=all', $cookie);
    p6_check($page['status'] === 200 && str_contains($page['body'], 'Refund confirmed by Maya') && str_contains($page['body'], 'Provider refund failed') && str_contains($page['body'], 'Provider evidence requires reconciliation'), 'the refunds page shows provider-backed lifecycle states');
    p6_check(str_contains($page['body'], 'does not submit refunds to Maya automatically'), 'the page states that approval is not a completed refund');
    $plainCookie = p6_login($plain);
    $deny = p7_post($plainCookie, 'page=admin-refunds', 'page=admin-refunds', ['action' => 'approve', 'refund_id' => (string) $open['id'], 'reason' => 'Unauthorized approval attempt']);
    p6_check($deny['status'] === 403 && $status((int) $open['id']) === 'requested', 'HTTP approval without permission is denied');
    p6_check(p7_audit('permission.denied')['actor_id'] == $plain['id'], 'the denied approval is audited');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 refund checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
