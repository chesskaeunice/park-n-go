<?php
declare(strict_types=1);

// Facility monitoring, derived occupancy, safe corrections, and active-session exceptions (tests/run-phase7.php p7_occupancy).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\{OperationsMonitor, PermissionDenied, ReconciliationService, SessionExceptionService};

$pdo = Database::connection(); $monitor = new OperationsMonitor();
$slot = static function (array $place, string $label, string $status = 'available') use ($pdo): int {
    $pdo->prepare('INSERT INTO parking_slots (floor_id,label,status,position_x,position_y) VALUES (?,?,?,?,?)')->execute([$place['floor'], $label, $status, random_int(0, 11), random_int(0, 9)]);
    return (int) $pdo->lastInsertId();
};
$find = static function (int $facility, int $id) use ($monitor): array { foreach ($monitor->slots($facility) as $row) if ((int) $row['id'] === $id) return $row; throw new RuntimeException('slot missing'); };
$history = static fn (): array => [(int) p6_value('SELECT COUNT(*) FROM reservations'), (int) p6_value('SELECT COUNT(*) FROM payment_attempts'), (int) p6_value('SELECT COUNT(*) FROM parking_sessions')];
try {
    $place = p6_facility(); $f = $place['facility']; $driver = p6_user('driver');
    $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=?")->execute([$place['slot']]);
    $available = $place['slot'];
    $occupied = p6_session($place);
    $overdue = p6_session($place, null, '-10 minutes', '-80 minutes');
    $reserved = $slot($place, 'R-1'); $p = $place; $p['slot'] = $reserved; p6_reservation($driver['id'], $p, p6_utc('-10 minutes'), p6_utc('+50 minutes'));
    $upcoming = $slot($place, 'U-1'); $p['slot'] = $upcoming; $upcomingRes = p6_reservation($driver['id'], $p, p6_utc('+3 hours'), p6_utc('+4 hours'));
    $held = $slot($place, 'H-1'); $p['slot'] = $held; $heldRes = p6_reservation($driver['id'], $p, p6_utc('-1 minute'), p6_utc('+1 hour'), 'pending'); p6_hold($driver['id'], $p, $heldRes, p6_utc('-1 minute'), p6_utc('+1 hour'), p6_utc('+8 minutes'));
    $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,'INITIAL_RESERVATION',5000,'PHP',?,?,'checkout_created',?)")->execute([$r = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))), $driver['id'], $heldRes, 'fake-' . bin2hex(random_bytes(12)), $r, p6_utc('+8 minutes')]);
    $stale = $slot($place, 'X-1'); $p['slot'] = $stale; $staleRes = p6_reservation($driver['id'], $p, p6_utc('-5 minutes'), p6_utc('+1 hour'), 'cancelled'); p6_hold($driver['id'], $p, $staleRes, p6_utc('-5 minutes'), p6_utc('+1 hour'), p6_utc('+6 minutes'));
    $maintenance = $slot($place, 'M-1', 'maintenance');
    $other = p6_facility(); p6_session($other);

    // Facility counts and derived availability.
    $m = $monitor->metrics($f);
    p6_check($m['facilities'] === 1 && $m['active_facilities'] === 1, 'facility filter scopes facility counts');
    p6_check($m['slots'] === 8, 'all eight slots of the facility are counted');
    p6_check($m['occupied'] === 2 && $m['active_sessions'] === 2, 'occupied slots come from active parking sessions');
    p6_check($m['overdue_sessions'] === 1, 'overdue sessions use paid-until and verified grace');
    p6_check($m['reserved'] === 1, 'a confirmed reservation covering now marks its slot reserved');
    p6_check($m['held'] === 2, 'live holds mark slots held, including a stale hold that still blocks the slot');
    p6_check($m['out_of_service'] === 1, 'maintenance slots are out of service');
    p6_check($m['available'] === 2, 'available excludes occupied, reserved, held, and out-of-service slots');
    p6_check($m['unpaid_holds'] === 1, 'unpaid holds count only live holds of pending reservations');
    p6_check($m['upcoming_confirmed'] === 1, 'upcoming confirmed reservations exclude those already entered or covering now');
    p6_check($m['pending_payments'] === 1, 'pending payment attempts are scoped to the facility');
    $all = $monitor->metrics(0);
    p6_check($all['facilities'] >= 2 && $all['active_sessions'] === $m['active_sessions'] + 1, 'the all-facilities view includes other facilities');
    p6_check($find($f, $available)['state'] === 'available' && $find($f, $upcoming)['state'] === 'available' && $find($f, $upcoming)['confirmed'][0]['id'] == $upcomingRes, 'a later booking leaves the slot available now and is shown as next');
    p6_check($find($f, $occupied['place']['slot'])['state'] === 'occupied' && $find($f, $occupied['place']['slot'])['last_event'][0] === 'Entry', 'occupied slot shows its session and last entry event');
    p6_check($find($f, $stale)['stale_holds'] && !$find($f, $held)['stale_holds'], 'stale holds are distinguished from holds protecting a pending checkout');

    // Safe corrections never override authoritative records.
    $before = $history();
    $try = static fn (int $id, string $action, string $reason = 'Physical inspection by the facility supervisor') => $monitor->correct(p7_admin()['id'], $id, $action, $find($f, $id)['fingerprint'], $reason);
    p6_throws(static fn () => $try($occupied['place']['slot'], 'mark_maintenance'), 'an occupied slot cannot be taken out of service', 'active parking session');
    p6_throws(static fn () => $try($reserved, 'mark_maintenance'), 'a slot with a confirmed reservation cannot be taken out of service', 'Confirmed reservations');
    p6_throws(static fn () => $try($upcoming, 'mark_disabled'), 'a slot with an upcoming confirmed booking cannot be disabled', 'Confirmed reservations');
    p6_throws(static fn () => $try($held, 'mark_maintenance'), 'a slot protecting a live checkout cannot be taken out of service', 'live checkouts');
    p6_throws(static fn () => $try($held, 'release_stale_holds'), 'a hold protecting a pending checkout is not released', 'no stale holds');
    p6_throws(static fn () => $try($available, 'mark_maintenance', 'short'), 'a correction needs a written reason', 'reason');
    p6_throws(static fn () => $try($available, 'set_occupied'), 'unsupported corrections are rejected', 'supported');
    p6_throws(static fn () => $monitor->correct(p7_admin()['id'], $available, 'mark_maintenance', str_repeat('0', 64), 'Inspection found a damaged bay marking'), 'a correction based on an outdated view is refused', 'changed since you reviewed');
    $pdo->prepare("UPDATE parking_slots SET status='maintenance' WHERE id=?")->execute([$occupied['place']['slot']]);
    p6_check($find($f, $occupied['place']['slot'])['state'] === 'maintenance', 'inconsistent data: an occupied slot marked maintenance is detected');
    p6_throws(static fn () => $try($occupied['place']['slot'], 'mark_available'), 'a slot is not toggled available while an active session exists', 'active-session exception');
    $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=?")->execute([$occupied['place']['slot']]);

    $admin = p7_admin();
    $after = $monitor->correct($admin['id'], $available, 'mark_maintenance', $find($f, $available)['fingerprint'], 'Water leak reported above the bay');
    p6_check($after['inventory_status'] === 'maintenance' && $after['state'] === 'maintenance', 'an idle slot is taken out of service');
    $event = p7_audit('occupancy.mark_maintenance', $available);
    p6_check($event && (int) $event['actor_id'] === $admin['id'] && (int) $event['facility_id'] === $f && $event['reason'] === 'Water leak reported above the bay'
        && json_decode((string) $event['before_state'], true)['state'] === 'available' && json_decode((string) $event['after_state'], true)['state'] === 'maintenance', 'correction audit records actor, facility, reason, and before/after state');
    $monitor->correct($admin['id'], $available, 'mark_available', $find($f, $available)['fingerprint'], 'Leak repaired and bay inspected');
    p6_check($find($f, $available)['state'] === 'available', 'the slot is restored to service');
    $released = $monitor->correct($admin['id'], $stale, 'release_stale_holds', $find($f, $stale)['fingerprint'], 'Hold left behind by a cancelled reservation');
    p6_check($released['state'] === 'available' && p6_value('SELECT released_at FROM reservation_holds WHERE reservation_id=?', [$staleRes]) !== null, 'a stale hold is released and the slot becomes available');
    p6_check(p6_value('SELECT status FROM reservations WHERE id=?', [$staleRes]) === 'cancelled' && $history() === $before, 'corrections preserve reservation, payment, and session history');

    // HTTP: the occupancy page renders derived state and applies a correction with PRG.
    $cookie = p6_login($admin);
    $page = p6_http('page=admin-occupancy&facility_id=' . $f, $cookie);
    p6_check($page['status'] === 200 && str_contains($page['body'], 'R-1') && str_contains($page['body'], 'Session exception'), 'occupancy page lists slots and session exception links');
    $attention = p6_http('page=admin-occupancy&state=attention&facility_id=' . $f, $cookie);
    p6_check(str_contains($attention['body'], 'Overdue') && !str_contains($attention['body'], 'M-1'), 'the needs-attention view lists overdue sessions only');
    preg_match('/name="slot_id" value="' . $maintenance . '"><input type="hidden" name="expected" value="([a-f0-9]{64})"/', $page['body'], $fp);
    $post = p6_http('page=admin-occupancy&facility_id=' . $f, $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'slot_id' => (string) $maintenance, 'expected' => $fp[1], 'action' => 'mark_available', 'reason' => 'Maintenance completed by contractor']);
    p6_check($post['status'] === 303 && p6_value('SELECT status FROM parking_slots WHERE id=?', [$maintenance]) === 'available', 'HTTP correction succeeds with a redirect');
    $replay = p6_http('page=admin-occupancy&facility_id=' . $f, $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'slot_id' => (string) $maintenance, 'expected' => $fp[1], 'action' => 'mark_disabled', 'reason' => 'Replaying an outdated form']);
    p6_check($replay['status'] === 422 && str_contains(p6_errors($replay['body']), 'changed since'), 'replaying the outdated form is refused with 422');

    // Active-session exceptions.
    $service = new SessionExceptionService(); $closer = p7_admin(['active_session_exception']); $plain = p7_admin();
    p6_throws(static fn () => $service->close($plain['id'], $overdue['session'], $f, 'emergency_closure', 'Fire alarm evacuation of the whole facility'), 'closing a session needs active_session_exception', '', PermissionDenied::class);
    p6_throws(static fn () => $service->close($closer['id'], $overdue['session'], $other['facility'], 'emergency_closure', 'Fire alarm evacuation of the whole facility'), 'the facility must match the session', 'another facility');
    p6_throws(static fn () => $service->close($closer['id'], $overdue['session'], $f, 'refund', 'Fire alarm evacuation of the whole facility'), 'the category must be one of the narrow exception types', 'Choose why');
    p6_throws(static fn () => $service->close($closer['id'], $overdue['session'], $f, 'emergency_closure', 'too short'), 'an exception needs a detailed reason', 'reason');
    $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,'OVERTIME_SETTLEMENT',417,'PHP',?,?,'checkout_created',?)")
        ->execute([$pending = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))), $overdue['driver']['id'], $overdue['session'], 'fake-' . bin2hex(random_bytes(12)), $pending, p6_utc('+5 minutes')]);
    $payments = (int) p6_value('SELECT COUNT(*) FROM payment_attempts'); $expected = $service->session($overdue['session'])['overtime'];
    $result = $service->close($closer['id'], $overdue['session'], $f, 'emergency_closure', 'Fire alarm evacuation of the whole facility');
    $exception = p6_row('SELECT * FROM parking_session_exceptions WHERE id=?', [$result['exception_id']]);
    p6_check(p6_session_row($overdue['session'])['status'] === 'completed' && p6_session_row($overdue['session'])['exit_at'] !== null && (int) p6_session_row($overdue['session'])['exited_by'] === $closer['id'], 'the session is completed with the admin recorded as exit actor');
    p6_check(p6_value('SELECT status FROM reservations WHERE id=?', [$overdue['reservation']]) === 'completed' && p6_value('SELECT status FROM qr_tickets WHERE id=?', [$overdue['ticket']]) === 'completed', 'reservation and QR ticket are completed');
    p6_check($exception['obligation_status'] === 'unsettled' && (int) $exception['outstanding_seconds'] >= (int) $expected['elapsed_seconds'] && (int) $exception['outstanding_centavos'] >= (int) $expected['estimated_centavos'] && (int) $exception['outstanding_rate_centavos'] === 2500, 'unpaid overtime is preserved as an unsettled obligation, not waived');
    p6_check((int) p6_value('SELECT COUNT(*) FROM payment_attempts') === $payments && p6_value('SELECT local_status FROM payment_attempts WHERE reference=?', [$pending]) === 'checkout_created', 'payment records, including a pending overtime checkout, are untouched');
    p6_check(p6_value('SELECT COUNT(*) FROM refund_requests') == 0, 'an emergency exit creates no refund');
    p6_check($find($f, $overdue['place']['slot'])['state'] === 'available', 'the slot is released after the exception');
    $case = array_values(array_filter((new ReconciliationService())->cases($f), static fn (array $c): bool => $c['key'] === 'exception:' . $result['exception_id']));
    p6_check($case && $case[0]['kinds'] === ['unsettled_obligation'], 'the unsettled obligation appears in the reconciliation queue');
    $event = p7_audit('session.exception_closed', $overdue['session']);
    p6_check($event && json_decode((string) $event['before_state'], true)['status'] === 'active' && json_decode((string) $event['after_state'], true)['obligation_status'] === 'unsettled' && in_array($pending, json_decode((string) $event['before_state'], true)['pending_payments'], true), 'exception audit records before/after state and the pending payment');
    p6_throws(static fn () => $service->close($closer['id'], $overdue['session'], $f, 'emergency_closure', 'Fire alarm evacuation of the whole facility'), 'a completed session cannot be closed again', 'no longer active');
    $paid = p6_session($place);
    $none = $service->close($closer['id'], $paid['session'], $f, 'kiosk_unavailable', 'Kiosk screen failure; driver within paid time');
    p6_check(!$none['unsettled'] && p6_value('SELECT obligation_status FROM parking_session_exceptions WHERE id=?', [$none['exception_id']]) === 'none', 'a session within paid time closes with no obligation');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 occupancy and session exception checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
