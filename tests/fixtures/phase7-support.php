<?php
declare(strict_types=1);

// Phase 7 fixtures on top of the Phase 6 helpers. Isolated *_test databases only (enforced by phase6-support).
require __DIR__ . '/phase6-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\AdminPermissions;

/** Admin with exactly the listed sensitive permissions (granted through the audited operator path). */
function p7_admin(array $permissions = []): array
{
    $admin = p6_user('admin');
    foreach ($permissions as $permission) AdminPermissions::change(null, $admin['id'], $permission, true, 'Phase 7 test fixture grant');
    return $admin;
}

/** A provider-verified payment attempt of any type, dated $verifiedAt (UTC), linked to a fresh session or reservation. */
function p7_payment(array $place, string $type, int $centavos, string $verifiedAt, string $status = 'verified', ?string $providerStatus = 'PAYMENT_SUCCESS'): array
{
    $pdo = Database::connection();
    $s = p6_session($place, null, '+2 hours', '-1 hour');
    $ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12)));
    $checkout = 'fake-' . bin2hex(random_bytes(12));
    if ($type === 'INITIAL_RESERVATION') {
        $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,provider_status,verified_at) VALUES (?,?,?,?,?,'PHP',?,?,?,?,?)")
            ->execute([$ref, $s['driver']['id'], $s['reservation'], $type, $centavos, $checkout, $ref, $status, $providerStatus, $status === 'verified' ? $verifiedAt : null]);
    } else {
        $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,provider_status,verified_at) VALUES (?,?,?,?,?,'PHP',?,?,?,?,?)")
            ->execute([$ref, $s['driver']['id'], $s['session'], $type, $centavos, $checkout, $ref, $status, $providerStatus, $status === 'verified' ? $verifiedAt : null]);
    }
    $id = (int) $pdo->lastInsertId();
    $ledger = $status === 'verified' ? 'verified' : ($status === 'reconciliation' ? 'reconciliation' : 'pending');
    // Ledger rows describe the same money as the attempt; reports must not add them again.
    if ($type === 'PARKING_EXTENSION') $pdo->prepare('INSERT INTO parking_extensions (parking_session_id,payment_attempt_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until,status,verified_at) VALUES (?,?,60,?,?,?,?,?,?)')
        ->execute([$s['session'], $id, $centavos, $centavos, p6_utc('+2 hours'), p6_utc('+3 hours'), $ledger, $status === 'verified' ? $verifiedAt : null]);
    if ($type === 'OVERTIME_SETTLEMENT') $pdo->prepare('INSERT INTO parking_overtime_settlements (parking_session_id,payment_attempt_id,interval_start,interval_end,billed_seconds,rate_centavos,amount_centavos,status,verified_at) VALUES (?,?,?,?,60,?,?,?,?)')
        ->execute([$s['session'], $id, p6_utc('-2 hours'), p6_utc('-119 minutes'), $centavos, $centavos, $ledger, $status === 'verified' ? $verifiedAt : null]);
    return ['id' => $id, 'reference' => $ref, 'checkout' => $checkout, 'session' => $s, 'amount' => $centavos];
}

/** Fake Maya payment record for MayaClient::retrievePayment(); status is whatever the test asserts Maya reports. */
function p7_fake_checkout(string $checkoutId, string $reference, int $centavos, string $status): void
{
    @mkdir(p6_fake_dir() . '/checkouts');
    file_put_contents(p6_fake_dir() . '/checkouts/' . $checkoutId . '.json', json_encode(['reference' => $reference, 'amount' => number_format($centavos / 100, 2, '.', ''), 'currency' => 'PHP', 'status' => $status, 'item' => 'fixture']));
}

/** Fake Maya refund evidence for MayaClient::retrieveRefund(). */
function p7_fake_refund(string $paymentId, string $refundId, int $centavos, string $status): void
{
    @mkdir(p6_fake_dir() . '/refunds');
    file_put_contents(p6_fake_dir() . '/refunds/' . $refundId . '.json', json_encode(['payment_id' => $paymentId, 'amount' => number_format($centavos / 100, 2, '.', ''), 'currency' => 'PHP', 'status' => $status]));
}

function p7_audit(string $action, ?int $targetId = null): array|false
{
    return p6_row('SELECT * FROM admin_audit_events WHERE action=?' . ($targetId === null ? '' : ' AND target_id=?') . ' ORDER BY id DESC LIMIT 1', $targetId === null ? [$action] : [$action, $targetId]);
}

/** Runs phase7-worker.php jobs in separate processes released together through a file barrier. */
function p7_race(array $jobs): array
{
    $barriers = BASE_PATH . '/storage/test-runtime/phase7-barriers';
    if (!is_dir($barriers)) mkdir($barriers, 0700, true);
    $barrier = bin2hex(random_bytes(8)); $processes = [];
    foreach ($jobs as $index => $job) {
        $process = proc_open([PHP_BINARY, BASE_PATH . '/tests/fixtures/phase7-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, BASE_PATH);
        if (!is_resource($process)) throw new RuntimeException('Unable to start worker.');
        fwrite($pipes[0], json_encode($job + ['barrier' => $barrier, 'worker' => $index], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barriers . '/' . $barrier . '-*.ready')) < count($jobs)) { if (microtime(true) > $deadline) throw new RuntimeException('Workers did not become ready.'); usleep(5000); }
    touch($barriers . '/' . $barrier . '.go');
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $results[] = json_decode($out, true) ?? ['result' => 'error', 'message' => trim($out . ' ' . $err)];
    }
    foreach (glob($barriers . '/' . $barrier . '*') as $file) unlink($file);
    return $results;
}

/** Admin HTTP form post using a CSRF token from the page that renders the form. */
function p7_post(string $cookie, string $formQuery, string $postQuery, array $fields): array
{
    $form = p6_http($formQuery, $cookie);
    // Pages hide forms an admin may not use; the session-wide token is then taken from the profile form.
    if (!str_contains($form['body'], 'name="csrf"')) $form = p6_http('page=profile', $cookie);
    return p6_http($postQuery, $cookie, 'POST', ['csrf' => p6_csrf($form['body'])] + $fields);
}
