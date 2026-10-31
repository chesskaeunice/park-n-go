<?php
declare(strict_types=1);

// Admin authorization over real HTTP routes (tests/run-phase7.php p7_permissions).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Admin\AdminPermissions;

$pages = ['admin-dashboard', 'admin-occupancy', 'admin-reconciliation', 'admin-refunds', 'admin-pricing', 'admin-reports', 'admin-audit', 'admin-permissions'];
$rateFields = static fn (string $reason, int $expected): array => ['initial_minutes' => '60', 'initial_centavos' => '55.00', 'succeeding_hour_centavos' => '22.50', 'overtime_hour_centavos' => '30', 'daily_max_centavos' => '', 'additional_centavos' => '0', 'effective_from' => '', 'reason' => $reason, 'expected_latest' => (string) $expected];
try {
    $place = p6_facility(); $facility = $place['facility'];
    $ordinary = p7_admin(); $pricer = p7_admin(['manage_pricing']); $approver = p7_admin(['approve_refunds']); $closer = p7_admin(['active_session_exception']); $manager = p7_admin(['manage_permissions']);
    $driver = p6_user('driver'); $attendant = p6_user('attendant');
    $c = ['ordinary' => p6_login($ordinary), 'pricer' => p6_login($pricer), 'approver' => p6_login($approver), 'closer' => p6_login($closer), 'manager' => p6_login($manager), 'driver' => p6_login($driver), 'attendant' => p6_login($attendant)];

    // Ordinary admin access: monitoring and reporting need no sensitive permission.
    foreach ($pages as $page) p6_check(p6_http('page=' . $page, $c['ordinary'])['status'] === 200, "ordinary admin can open $page");
    p6_check(p6_http('page=admin-report-export&type=revenue&format=csv', $c['ordinary'])['status'] === 200, 'ordinary admin can export a report');
    p6_check(AdminPermissions::forUser($ordinary['id']) === array_fill_keys(array_keys(AdminPermissions::ALL), false), 'the admin role alone grants no sensitive permission');

    // Driver, attendant, guest, and kiosk sessions are denied.
    foreach ($pages as $page) p6_check(p6_http('page=' . $page, $c['driver'])['status'] === 403, "driver is denied $page");
    p6_check(p6_http('page=admin-report-export&type=revenue&format=csv', $c['driver'])['status'] === 403, 'driver is denied report export');
    p6_check(p6_http('page=admin-reconciliation', $c['attendant'])['status'] === 403, 'attendant is denied admin operations');
    $guest = p6_cookie(); $redirect = p6_http('page=admin-refunds', $guest);
    p6_check($redirect['status'] === 303 && str_contains((string) $redirect['location'], 'page=login'), 'guest is sent to log in');
    $kiosk = p6_kiosk($facility, $ordinary['id']);
    $registered = p7_audit('kiosk.registered');
    p6_check($registered && (int) $registered['actor_id'] === $ordinary['id'] && (int) $registered['facility_id'] === $facility, 'kiosk registration is recorded in the admin audit trail with its facility');
    p6_check(p6_http('page=admin-dashboard', $kiosk)['status'] === 403, 'kiosk session is denied the admin dashboard');
    p6_check(p6_http('page=admin-report-export&type=revenue&format=csv', $kiosk)['status'] === 403, 'kiosk session is denied report export');
    $driverPage = p6_http('page=profile', $c['driver']);
    $driverPost = p6_http('page=admin-pricing&facility_id=' . $facility, $c['driver'], 'POST', ['csrf' => p6_csrf($driverPage['body'])] + $rateFields('Driver attempting a rate change', 0));
    p6_check($driverPost['status'] === 403, 'driver POST to pricing is denied by role');
    $mixed = p6_login($ordinary);
    p6_check(p6_http('page=kiosk-home', $mixed)['status'] === 403, 'an admin web session is not a kiosk session');
    // That request issued a kiosk session cookie; a browser holding it is treated as a kiosk and loses admin access.
    p6_check(p6_http('page=admin-dashboard', $mixed)['status'] === 403, 'a browser carrying a kiosk session cookie is denied admin pages');

    // CSRF is enforced before any admin action.
    $before = (int) p6_value('SELECT COUNT(*) FROM parking_rates WHERE facility_id=?', [$facility]);
    p6_check(p6_http('page=admin-pricing&facility_id=' . $facility, $c['pricer'], 'POST', $rateFields('Missing CSRF token test', $place['rate']))['status'] === 419, 'pricing POST without CSRF is rejected');
    p6_check(p6_http('page=admin-pricing&facility_id=' . $facility, $c['pricer'], 'POST', ['csrf' => str_repeat('a', 64)] + $rateFields('Forged CSRF token test', $place['rate']))['status'] === 419, 'pricing POST with a forged CSRF token is rejected');
    p6_check((int) p6_value('SELECT COUNT(*) FROM parking_rates WHERE facility_id=?', [$facility]) === $before, 'rejected CSRF requests created no rate');

    // manage_pricing allowed/denied.
    $denied = p7_post($c['ordinary'], 'page=admin-pricing&facility_id=' . $facility, 'page=admin-pricing&facility_id=' . $facility, $rateFields('Ordinary admin rate change', $place['rate']));
    p6_check($denied['status'] === 403 && str_contains(p6_errors($denied['body']), 'Manage pricing'), 'ordinary admin pricing change is denied with 403');
    p6_check((int) p6_value('SELECT COUNT(*) FROM parking_rates WHERE facility_id=?', [$facility]) === $before, 'denied pricing change created no rate');
    $audit = p7_audit('permission.denied');
    p6_check($audit && (int) $audit['actor_id'] === $ordinary['id'] && $audit['outcome'] === 'denied' && str_contains((string) $audit['after_state'], 'manage_pricing'), 'denied pricing attempt is audited with outcome denied');
    p6_check(!str_contains(p6_http('page=admin-pricing&facility_id=' . $facility, $c['ordinary'])['body'], 'Schedule rate'), 'pricing form is hidden from admins without permission (UI only)');
    $allowed = p7_post($c['pricer'], 'page=admin-pricing&facility_id=' . $facility, 'page=admin-pricing&facility_id=' . $facility, $rateFields('Quarterly rate review', $place['rate']));
    p6_check($allowed['status'] === 303 && (int) p6_value('SELECT COUNT(*) FROM parking_rates WHERE facility_id=?', [$facility]) === $before + 1, 'manage_pricing admin schedules a rate');

    // approve_refunds allowed/denied; any admin may only request.
    $payment = p7_payment($place, 'INITIAL_RESERVATION', 5000, p6_utc());
    $form = p6_http('page=admin-refunds&payment=' . $payment['reference'], $c['ordinary']);
    preg_match('/name="request_key" value="([a-f0-9]{64})"/', $form['body'], $key);
    $request = p6_http('page=admin-refunds', $c['ordinary'], 'POST', ['csrf' => p6_csrf($form['body']), 'action' => 'request', 'payment' => $payment['reference'], 'amount' => '20.00', 'reason' => 'Driver was double charged at the gate', 'request_key' => $key[1]]);
    $refund = p6_row('SELECT * FROM refund_requests WHERE payment_attempt_id=?', [$payment['id']]);
    p6_check($request['status'] === 303 && $refund && $refund['status'] === 'requested', 'ordinary admin can request a refund');
    $approve = static fn (string $cookie): array => p7_post($cookie, 'page=admin-refunds', 'page=admin-refunds', ['action' => 'approve', 'refund_id' => (string) $refund['id'], 'reason' => 'Verified duplicate charge evidence']);
    p6_check($approve($c['ordinary'])['status'] === 403 && p6_value('SELECT status FROM refund_requests WHERE id=?', [$refund['id']]) === 'requested', 'ordinary admin cannot approve a refund');
    p6_check($approve($c['pricer'])['status'] === 403, 'pricing permission does not allow refund approval');
    p6_check($approve($c['approver'])['status'] === 303 && p6_value('SELECT status FROM refund_requests WHERE id=?', [$refund['id']]) === 'approved', 'approve_refunds admin approves the refund');

    // active_session_exception allowed/denied.
    $s = p6_session($place);
    $preview = static fn (string $cookie): array => p7_post($cookie, 'page=admin-session-exception&session_id=' . $s['session'], 'page=admin-session-exception', ['action' => 'preview', 'session_id' => (string) $s['session'], 'facility_id' => (string) $facility, 'category' => 'kiosk_unavailable', 'reason' => 'Exit kiosk offline during a power interruption at the gate']);
    p6_check($preview($c['ordinary'])['status'] === 403, 'ordinary admin cannot preview an active-session exception');
    p6_check($preview($c['approver'])['status'] === 403, 'refund permission does not allow session exceptions');
    $shown = $preview($c['closer']);
    p6_check($shown['status'] === 200 && str_contains($shown['body'], 'Confirm the exception') && p6_value('SELECT status FROM parking_sessions WHERE id=?', [$s['session']]) === 'active', 'permitted admin sees a confirmation step and nothing changes yet');
    preg_match('/name="token" value="([a-f0-9]{32})"/', $shown['body'], $token);
    $unticked = p6_http('page=admin-session-exception', $c['closer'], 'POST', ['csrf' => p6_csrf($shown['body']), 'action' => 'confirm', 'session_id' => (string) $s['session'], 'token' => $token[1]]);
    p6_check($unticked['status'] === 422 && p6_value('SELECT status FROM parking_sessions WHERE id=?', [$s['session']]) === 'active', 'confirmation without the explicit tick is refused');
    $shown = $preview($c['closer']); preg_match('/name="token" value="([a-f0-9]{32})"/', $shown['body'], $token);
    $confirmed = p6_http('page=admin-session-exception', $c['closer'], 'POST', ['csrf' => p6_csrf($shown['body']), 'action' => 'confirm', 'session_id' => (string) $s['session'], 'token' => $token[1], 'confirm' => 'yes']);
    p6_check($confirmed['status'] === 303 && p6_value('SELECT status FROM parking_sessions WHERE id=?', [$s['session']]) === 'completed', 'confirmed exception closes the session');

    // Sensitive permission changes: managed, never self-service, effective immediately.
    $grant = static fn (string $cookie, int $user, string $permission, string $action = 'grant'): array => p7_post($cookie, 'page=admin-permissions', 'page=admin-permissions', ['action' => $action, 'user_id' => (string) $user, 'permission' => $permission, 'reason' => 'Assigned refund duty for the evening shift']);
    p6_check($grant($c['ordinary'], $ordinary['id'], 'approve_refunds')['status'] === 403, 'admin without manage_permissions cannot grant permissions');
    p6_check($grant($c['manager'], $manager['id'], 'approve_refunds')['status'] === 422 && !AdminPermissions::has($manager['id'], 'approve_refunds'), 'a manager cannot grant themselves a permission');
    p6_check($grant($c['manager'], $driver['id'], 'approve_refunds')['status'] === 422, 'permissions cannot be granted to non-admin accounts');
    p6_check($grant($c['manager'], $ordinary['id'], 'manage_pricing')['status'] === 303 && AdminPermissions::has($ordinary['id'], 'manage_pricing'), 'manager grants another admin a permission');
    $event = p7_audit('permission.granted', $ordinary['id']);
    p6_check($event && (int) $event['actor_id'] === $manager['id'] && json_decode((string) $event['before_state'], true)['granted'] === false && json_decode((string) $event['after_state'], true)['granted'] === true, 'permission grant is audited with before and after state');
    p6_check($grant($c['manager'], $pricer['id'], 'manage_pricing', 'revoke')['status'] === 303 && !AdminPermissions::has($pricer['id'], 'manage_pricing'), 'manager revokes a permission');
    $latest = (int) p6_value('SELECT id FROM parking_rates WHERE facility_id=? ORDER BY effective_from DESC,id DESC LIMIT 1', [$facility]);
    p6_check(p7_post($c['pricer'], 'page=admin-pricing&facility_id=' . $facility, 'page=admin-pricing&facility_id=' . $facility, $rateFields('Change after revocation', $latest))['status'] === 403, 'revocation takes effect on the next request of an existing session');
    p6_throws(static fn () => AdminPermissions::change(null, $ordinary['id'], 'superuser', true, 'Unknown permission name'), 'unknown permission names are rejected', 'valid permission');
    p6_throws(static fn () => ParkNGo\Services\Database::connection()->exec("INSERT INTO admin_permissions (user_id,permission) VALUES ({$ordinary['id']},'superuser')"), 'the schema rejects unknown permission names', 'chk_admin_permission_name');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 authorization checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
