<?php
declare(strict_types=1);

// Separate-process races for Phase 7 sensitive writes (tests/run-phase7.php p7_concurrency).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\{OperationsMonitor, RefundService};
use ParkNGo\Services\Parking\{AvailabilityWindow, ReservationService};

$pdo = Database::connection(); $monitor = new OperationsMonitor();
$fingerprint = static function (int $facility, int $slot) use ($monitor): string { foreach ($monitor->slots($facility) as $row) if ((int) $row['id'] === $slot) return $row['fingerprint']; throw new RuntimeException('slot missing'); };
$outcomes = static function (array $rows): array { $counts = array_count_values(array_column($rows, 'result')); ksort($counts); return $counts; };
try {
    // 1. Two admins correct the same slot from the same reviewed state: exactly one wins, each round.
    $place = p6_facility(); $a = p7_admin(); $b = p7_admin();
    for ($round = 1; $round <= 3; $round++) {
        $expected = $fingerprint($place['facility'], $place['slot']); $status = p6_value('SELECT status FROM parking_slots WHERE id=?', [$place['slot']]);
        [$one, $two] = $status === 'available' ? ['mark_maintenance', 'mark_disabled'] : ['mark_available', $status === 'maintenance' ? 'mark_disabled' : 'mark_maintenance'];
        $rows = p7_race([['mode' => 'correct', 'admin' => $a['id'], 'slot' => $place['slot'], 'action' => $one, 'expected' => $expected], ['mode' => 'correct', 'admin' => $b['id'], 'slot' => $place['slot'], 'action' => $two, 'expected' => $expected]]);
        $done = array_values(array_filter($rows, static fn (array $r): bool => $r['result'] === 'done'));
        if (count($done) !== 1) echo 'race outcome: ' . json_encode($rows) . "\n";
        p6_check($outcomes($rows) === ['conflict' => 1, 'done' => 1] && p6_value('SELECT status FROM parking_slots WHERE id=?', [$place['slot']]) === $done[0]['state'], "round $round: concurrent corrections from one reviewed state apply exactly once");
    }
    p6_check((int) p6_value("SELECT COUNT(*) FROM admin_audit_events WHERE target_type='parking_slot' AND target_id=?", [$place['slot']]) === 3, 'only winning corrections are audited');

    // 2. A correction racing a new reservation on the same slot: never both.
    $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=?")->execute([$place['slot']]);
    $driver = p6_user('driver');
    $local = (new DateTimeImmutable('+2 hours', new DateTimeZone('Asia/Manila')))->setTime((int) (new DateTimeImmutable('+2 hours', new DateTimeZone('Asia/Manila')))->format('H'), 0);
    $window = ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'hours' => '1'];
    $quote = (new ReservationService())->quote($place['slot'], new AvailabilityWindow($window));
    $rows = p7_race([['mode' => 'correct', 'admin' => $a['id'], 'slot' => $place['slot'], 'action' => 'mark_maintenance', 'expected' => $fingerprint($place['facility'], $place['slot'])],
        ['mode' => 'reserve', 'user' => $driver['id'], 'slot' => $place['slot'], 'window' => $window, 'key' => bin2hex(random_bytes(32)), 'quote_hash' => $quote['quote_hash']]]);
    $reserved = (int) p6_value("SELECT COUNT(*) FROM reservations WHERE slot_id=? AND status='pending'", [$place['slot']]); $status = p6_value('SELECT status FROM parking_slots WHERE id=?', [$place['slot']]);
    p6_check($outcomes($rows) === ['conflict' => 1, 'done' => 1] && (($reserved === 1 && $status === 'available') || ($reserved === 0 && $status === 'maintenance')), 'a correction and a new booking never both succeed on one slot');

    // 3. Concurrent pricing changes from the same reviewed rate: one new version only.
    $pricers = [p7_admin(['manage_pricing']), p7_admin(['manage_pricing'])];
    $values = static fn (string $initial): array => ['initial_minutes' => '60', 'initial_centavos' => $initial, 'succeeding_hour_centavos' => '25', 'overtime_hour_centavos' => '30', 'daily_max_centavos' => '', 'additional_centavos' => '0'];
    $count = (int) p6_value('SELECT COUNT(*) FROM parking_rates WHERE facility_id=?', [$place['facility']]);
    $rows = p7_race([['mode' => 'price', 'admin' => $pricers[0]['id'], 'facility' => $place['facility'], 'values' => $values('60'), 'expected' => $place['rate']], ['mode' => 'price', 'admin' => $pricers[1]['id'], 'facility' => $place['facility'], 'values' => $values('65'), 'expected' => $place['rate']]]);
    p6_check($outcomes($rows) === ['conflict' => 1, 'done' => 1] && (int) p6_value('SELECT COUNT(*) FROM parking_rates WHERE facility_id=?', [$place['facility']]) === $count + 1, 'concurrent pricing updates create exactly one new rate version');
    p6_check((int) p6_value("SELECT COUNT(*) FROM admin_audit_events WHERE action='pricing.rate_scheduled' AND facility_id=?", [$place['facility']]) === 1, 'only the winning pricing change is audited');

    // 4. Refund requests: different forms for one payment create one open request; the same form is idempotent.
    $payment = p7_payment($place, 'INITIAL_RESERVATION', 5000, p6_utc()); $requester = p7_admin(); $other = p7_admin();
    $rows = p7_race([['mode' => 'request', 'admin' => $requester['id'], 'payment' => $payment['reference'], 'amount' => '30.00', 'key' => bin2hex(random_bytes(32))], ['mode' => 'request', 'admin' => $other['id'], 'payment' => $payment['reference'], 'amount' => '30.00', 'key' => bin2hex(random_bytes(32))]]);
    p6_check($outcomes($rows) === ['conflict' => 1, 'done' => 1] && (int) p6_value('SELECT COUNT(*) FROM refund_requests WHERE payment_attempt_id=?', [$payment['id']]) === 1, 'simultaneous refund requests for one payment create a single open request');
    $second = p7_payment($place, 'INITIAL_RESERVATION', 5000, p6_utc()); $key = bin2hex(random_bytes(32));
    $rows = p7_race([['mode' => 'request', 'admin' => $requester['id'], 'payment' => $second['reference'], 'amount' => '10.00', 'key' => $key], ['mode' => 'request', 'admin' => $requester['id'], 'payment' => $second['reference'], 'amount' => '10.00', 'key' => $key]]);
    if ($outcomes($rows) !== ['done' => 2]) echo 'race outcome: ' . json_encode($rows) . "\n";
    p6_check($outcomes($rows) === ['done' => 2] && $rows[0]['reference'] === $rows[1]['reference'] && (int) p6_value('SELECT COUNT(*) FROM refund_requests WHERE payment_attempt_id=?', [$second['id']]) === 1, 'a double-submitted refund form is idempotent');

    // 5. Two approvers approve the same refund at once: one decision.
    $refund = (int) p6_value('SELECT id FROM refund_requests WHERE payment_attempt_id=?', [$payment['id']]);
    $approvers = [p7_admin(['approve_refunds']), p7_admin(['approve_refunds'])];
    $rows = p7_race([['mode' => 'approve', 'admin' => $approvers[0]['id'], 'refund' => $refund], ['mode' => 'approve', 'admin' => $approvers[1]['id'], 'refund' => $refund]]);
    p6_check($outcomes($rows) === ['conflict' => 1, 'done' => 1] && (int) p6_value("SELECT COUNT(*) FROM admin_audit_events WHERE action='refund.approved' AND target_id=?", [$refund]) === 1, 'concurrent approvals produce exactly one approval');
    p6_check((new RefundService())->payment($payment['reference'])['refundable_centavos'] === 2000, 'the approved amount is reserved exactly once');

    // 6. Two admins close the same active session by exception: one closure, one obligation record.
    $closers = [p7_admin(['active_session_exception']), p7_admin(['active_session_exception'])];
    $s = p6_session($place, null, '-15 minutes', '-75 minutes');
    $rows = p7_race([['mode' => 'exception', 'admin' => $closers[0]['id'], 'session' => $s['session'], 'facility' => $place['facility']], ['mode' => 'exception', 'admin' => $closers[1]['id'], 'session' => $s['session'], 'facility' => $place['facility']]]);
    p6_check($outcomes($rows) === ['conflict' => 1, 'done' => 1] && (int) p6_value('SELECT COUNT(*) FROM parking_session_exceptions WHERE parking_session_id=?', [$s['session']]) === 1, 'concurrent exception closures complete the session once');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 concurrency checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
