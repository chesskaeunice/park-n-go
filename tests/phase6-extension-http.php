<?php
declare(strict_types=1);

// End-to-end Phase 6 HTTP flow plus extension HTTP/security checks (tests/run-phase6.php http_flow).
// Reservation -> Maya-verified payment -> QR -> entry -> extension -> kiosk exit -> session closed -> slot released.
require __DIR__ . '/fixtures/phase6-support.php';

use ParkNGo\Services\{Database, KioskService};

$pdo = Database::connection();
$plus = static fn(string $utc, int $minutes): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
$slotStatus = static function (array $place): string {
    $api = str_replace('/index.php', '/api.php', p6_base()) . '?' . http_build_query(['resource' => 'availability', 'facility_id' => $place['facility'], 'floor_id' => $place['floor']]);
    $data = json_decode((string) file_get_contents($api), true);
    foreach ($data['data']['slots'] as $slot) if ((int) $slot['id'] === $place['slot']) return $slot['status'];
    throw new RuntimeException('Slot missing from availability.');
};
$ticketPage = static function (string $cookie, int $reservation): string {
    $page = p6_http('page=ticket&id=' . $reservation, $cookie);
    if (!preg_match('#<details class="manual-code">.*?<code>([A-Z0-9]+)</code>#s', $page['body'], $match)) throw new RuntimeException('Manual code missing from ticket page.');
    return $match[1];
};

try {
    $place = p6_facility(2000, 2500);
    $driver = p6_user('driver'); $attendant = p6_user('attendant'); $intruder = p6_user('driver');
    $pdo->prepare('INSERT INTO attendant_facilities (attendant_id,facility_id) VALUES (?,?)')->execute([$attendant['id'], $place['facility']]);
    $driverCookie = p6_login($driver);

    // --- Reservation through the real review/checkout pages ---
    $local = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $review = p6_http('page=reservation-review&' . http_build_query(['slot_id' => $place['slot'], 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'hours' => '1']), $driverCookie);
    if (!preg_match('/name="request_key" value="([a-f0-9]{64})"/', $review['body'], $key)) throw new RuntimeException('Review form missing.');
    $hold = p6_http('page=reservation-review', $driverCookie, 'POST', ['csrf' => p6_csrf($review['body']), 'request_key' => $key[1]]);
    parse_str((string) parse_url((string) $hold['location'], PHP_URL_QUERY), $query); $reservation = (int) ($query['id'] ?? 0);
    p6_check($hold['status'] === 303 && $reservation > 0 && p6_value('SELECT status FROM reservations WHERE id=?', [$reservation]) === 'pending', 'driver creates a pending reservation hold through the review page');
    $checkout = p6_http('page=reservation-checkout&id=' . $reservation, $driverCookie);
    $pay = p6_http('page=payment-start', $driverCookie, 'POST', ['csrf' => p6_csrf($checkout['body']), 'id' => $reservation]);
    $initial = p6_attempt_by_url((string) $pay['location']);
    p6_check($pay['status'] === 303 && $initial['local_status'] === 'checkout_created' && p6_fake_checkout((string) $initial['maya_checkout_id'])['item'] === 'Parking reservation', 'payment start redirects to a Maya checkout created server-side');
    $early = p6_http('page=payment-return&reference=' . $initial['reference'], $driverCookie);
    p6_check(!str_contains($early['body'], 'View secure QR ticket') && p6_value('SELECT status FROM reservations WHERE id=?', [$reservation]) === 'pending', 'returning from Maya without provider success issues no QR');
    p6_fake_status((string) $initial['maya_checkout_id'], 'PAYMENT_SUCCESS');
    $refresh = p6_http('page=payment-refresh', $driverCookie, 'POST', ['csrf' => p6_csrf($early['body']), 'reference' => $initial['reference']]);
    $verified = p6_http('page=payment-return&checked=1&reference=' . $initial['reference'], $driverCookie);
    p6_check($refresh['status'] === 303 && str_contains($verified['body'], 'View secure QR ticket') && p6_value('SELECT status FROM reservations WHERE id=?', [$reservation]) === 'confirmed', 'server-side Maya verification confirms the reservation');
    $manual = $ticketPage($driverCookie, $reservation);
    p6_check(p6_http('page=ticket&id=' . $reservation, p6_login($intruder))['status'] === 404, 'another driver cannot open this QR ticket');

    // --- Entry by an authorized attendant ---
    $attendantCookie = p6_login($attendant);
    $scanner = p6_http('page=entry-scan', $attendantCookie);
    $entry = p6_http('page=entry-scan', $attendantCookie, 'POST', ['csrf' => p6_csrf($scanner['body']), 'code' => $manual]);
    $session = p6_row('SELECT * FROM parking_sessions WHERE reservation_id=?', [$reservation]);
    p6_check($entry['status'] === 200 && str_contains($entry['body'], 'Scan accepted') && $session && $session['status'] === 'active' && (int) $session['purchased_minutes'] === 60, 'valid entry starts the active session and paid duration');
    $replay = p6_http('page=entry-scan', $attendantCookie, 'POST', ['csrf' => p6_csrf($entry['body']), 'code' => $manual]);
    p6_check($replay['status'] === 422 && str_contains($replay['body'], 'Duplicate entry'), 'QR replay at entry is rejected');
    p6_check($slotStatus($place) === 'occupied', 'slot shows occupied during the active session');
    p6_check(p6_http('page=exit-scan', $driverCookie)['status'] === 403 && p6_http('page=entry-scan', $driverCookie)['status'] === 403, 'drivers cannot reach attendant scanners');
    $ticketRow = p6_row('SELECT id,token_hash,manual_code_hash FROM qr_tickets WHERE reservation_id=?', [$reservation]);

    // --- Extension over HTTP: CSRF, method, ownership, tampering, pending, failed, success ---
    $dash = p6_http('page=dashboard', $driverCookie);
    p6_check(str_contains($dash['body'], 'id="active-session"') && str_contains($dash['body'], 'name="blocks[]" value="3"') && str_contains($dash['body'], '₱20.00 per hour'), 'mobile dashboard shows the active session, block choices, and hourly rate');
    $before = (int) p6_value('SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=?', [$session['id']]);
    p6_check(p6_http('page=extension-start', $driverCookie, 'POST', ['session_id' => $session['id'], 'blocks' => ['1']])['status'] === 419, 'extension start without CSRF is rejected');
    p6_check(p6_http('page=extension-start&session_id=' . $session['id'], $driverCookie)['status'] === 405, 'extension start requires POST');
    $intruderCookie = p6_login($intruder); $intruderDash = p6_http('page=profile', $intruderCookie);
    $stolen = p6_http('page=extension-start', $intruderCookie, 'POST', ['csrf' => p6_csrf($intruderDash['body']), 'session_id' => $session['id'], 'blocks' => ['1']]);
    p6_check($stolen['status'] === 303 && str_contains(p6_errors(p6_http('page=dashboard', $intruderCookie)['body']), 'not found'), "another driver cannot extend this driver's session");
    $kioskOnly = p6_kiosk($place['facility']);
    p6_check(p6_http('page=dashboard', $kioskOnly)['status'] === 403, 'an activated kiosk browser cannot reach driver payment routes');
    p6_check((int) p6_value('SELECT COUNT(*) FROM payment_attempts WHERE parking_session_id=?', [$session['id']]) === $before, 'rejected requests created no payment attempts');
    p6_fake_mode(['checkout' => 'error500']);
    $down = p6_http('page=extension-start', $driverCookie, 'POST', ['csrf' => p6_csrf($dash['body']), 'session_id' => $session['id'], 'blocks' => ['1']]);
    $downAttempt = p6_latest_attempt((int) $session['id']); $downPage = p6_http('page=dashboard', $driverCookie);
    p6_check($down['status'] === 303 && str_contains(p6_errors($downPage['body']), 'No payment was taken') && $downAttempt['local_status'] === 'failed' && p6_ledger($downAttempt)['status'] === 'failed', 'Maya outage shows a safe message and releases the attempt and ledger');
    p6_check(!str_contains($downPage['body'], (string) getenv('FAKE_MAYA_SECRET_KEY')) && !str_contains($downPage['body'], 'pg-sandbox') && !str_contains($downPage['body'], '127.0.0.1:8093'), 'provider failure exposes no credentials or provider endpoints');
    p6_fake_mode([]);
    $start = p6_http('page=extension-start', $driverCookie, 'POST', ['csrf' => p6_csrf($downPage['body']), 'session_id' => $session['id'], 'blocks' => ['1', '2'], 'amount_centavos' => '1', 'rate' => '1', 'paid_until' => '2099-01-01 00:00:00', 'hours' => '24']);
    $ext = p6_attempt_by_url((string) $start['location']); $ledger = p6_ledger($ext);
    p6_check($start['status'] === 303 && (int) $ledger['duration_minutes'] === 180 && (int) $ext['amount_centavos'] === 6000 && p6_fake_checkout((string) $ext['maya_checkout_id'])['amount'] === '60.00', 'extension amount is server-calculated; tampered amount/rate/paid-until fields are ignored');
    $pending = p6_http('page=payment-return&reference=' . $ext['reference'], $driverCookie);
    p6_check(str_contains($pending['body'], 'awaiting independent server verification') && p6_session_row((int) $session['id'])['paid_until'] === $session['paid_until'], 'pending extension payment shows its status and does not extend');
    p6_fake_status((string) $ext['maya_checkout_id'], 'PAYMENT_FAILED');
    p6_http('page=payment-refresh', $driverCookie, 'POST', ['csrf' => p6_csrf($pending['body']), 'reference' => $ext['reference']]);
    $failed = p6_http('page=payment-return&checked=1&reference=' . $ext['reference'], $driverCookie);
    p6_check(str_contains($failed['body'], '>Failed<') && str_contains($failed['body'], 'Return to your parking session') && p6_session_row((int) $session['id'])['paid_until'] === $session['paid_until'], 'failed extension payment is reported and paid-until is unchanged');
    $dash = p6_http('page=dashboard', $driverCookie);
    $retry = p6_http('page=extension-start', $driverCookie, 'POST', ['csrf' => p6_csrf($dash['body']), 'session_id' => $session['id'], 'blocks' => ['2']]);
    $ext = p6_attempt_by_url((string) $retry['location']);
    p6_fake_status((string) $ext['maya_checkout_id'], 'PAYMENT_SUCCESS');
    $done = p6_http('page=payment-return&reference=' . $ext['reference'], $driverCookie);
    p6_http('page=payment-return&reference=' . $ext['reference'], $driverCookie);
    $extended = p6_session_row((int) $session['id']);
    p6_check(str_contains($done['body'], 'parking session has been extended') && $extended['paid_until'] === $plus($session['paid_until'], 120), 'verified extension adds exactly two hours once');
    p6_check((int) $extended['id'] === (int) $session['id'] && p6_row('SELECT id,token_hash,manual_code_hash FROM qr_tickets WHERE reservation_id=?', [$reservation]) === $ticketRow, 'extension keeps the same parking session and QR ticket');
    p6_check(str_contains(p6_http('page=dashboard', $driverCookie)['body'], 'Your session is within its paid period.'), 'dashboard reflects the extended paid period');

    // --- Kiosk security around exit ---
    $kiosk = p6_kiosk($place['facility']);
    $other = p6_facility(); $wrongKiosk = p6_kiosk($other['facility']);
    [$verify, , $body] = p6_kiosk_exit($wrongKiosk, $manual);
    p6_check($verify === 422 && str_contains($body, 'another facility'), 'wrong-facility kiosk is denied');
    $home = p6_http('page=kiosk-home', $kiosk);
    p6_check(p6_http('page=kiosk-verify', $kiosk, 'POST', ['code' => $manual])['status'] === 419 && p6_http('page=kiosk-exit', $kiosk, 'POST', [])['status'] === 419, 'kiosk verify and confirm require CSRF');
    p6_check(p6_http('page=kiosk-exit', $kiosk, 'POST', ['csrf' => p6_csrf($home['body'])])['status'] === 422, 'Confirm Exit without a fresh verification is refused');
    p6_check(p6_http('page=kiosk-home', $driverCookie)['status'] === 403, 'a driver session is not a kiosk session');
    $overdue = p6_session($place, null, '-5 minutes', '-65 minutes');
    [$verify, , $body] = p6_kiosk_exit($kiosk, $overdue['manual']);
    p6_check($verify === 422 && str_contains($body, 'unpaid overtime'), 'unpaid overtime blocks exit at the kiosk');
    $overdueDriver = p6_login($overdue['driver']); $overdueDash = p6_http('page=dashboard', $overdueDriver);
    $blocked = p6_http('page=extension-start', $overdueDriver, 'POST', ['csrf' => p6_csrf($overdueDash['body']), 'session_id' => $overdue['session'], 'blocks' => ['1']]);
    p6_check($blocked['status'] === 303 && str_contains(p6_errors(p6_http('page=dashboard', $overdueDriver)['body']), 'Settle verified overtime') && !p6_latest_attempt($overdue['session']), 'overdue driver is told to settle overtime before extending');

    // --- Exit, session closure, slot release, duplicate exit and QR replay ---
    [$verify, $confirm, $body] = p6_kiosk_exit($kiosk, $manual);
    $closed = p6_session_row((int) $session['id']);
    p6_check($verify === 200 && $confirm === 200 && str_contains($body, 'Exit confirmed') && $closed['status'] === 'completed' && $closed['exit_at'] !== null, 'kiosk Confirm Exit closes the session atomically');
    p6_check(p6_value('SELECT status FROM reservations WHERE id=?', [$reservation]) === 'completed' && p6_value('SELECT status FROM qr_tickets WHERE reservation_id=?', [$reservation]) === 'completed' && $slotStatus($place) === 'available', 'reservation and ticket complete and the slot is released');
    [$verify, , $body] = p6_kiosk_exit($kiosk, $manual);
    p6_check($verify === 422 && str_contains($body, 'invalid or expired'), 'duplicate exit / QR replay after exit is rejected');
    $exitScan = p6_http('page=exit-scan', $attendantCookie);
    p6_check(p6_http('page=exit-scan', $attendantCookie, 'POST', ['csrf' => p6_csrf($exitScan['body']), 'code' => $manual])['status'] === 422, 'attendant exit replay is rejected too');
    $after = p6_http('page=dashboard', $driverCookie);
    p6_check(!str_contains($after['body'], 'id="active-session"'), 'dashboard no longer shows a session after exit');

    // --- Revoked kiosk ---
    $admin = p6_user('admin'); $revocable = KioskService::create($admin['id'], $place['facility'], 'Revocable gate');
    $revokedCookie = p6_cookie(); $page = p6_http('page=kiosk-activate', $revokedCookie);
    p6_http('page=kiosk-activate', $revokedCookie, 'POST', ['csrf' => p6_csrf($page['body']), 'code' => $revocable['code']]);
    $live = p6_session($place); $home = p6_http('page=kiosk-home', $revokedCookie);
    $preview = p6_http('page=kiosk-verify', $revokedCookie, 'POST', ['csrf' => p6_csrf($home['body']), 'code' => $live['manual']]);
    KioskService::revoke($admin['id'], (int) $revocable['id']);
    $confirm = p6_http('page=kiosk-exit', $revokedCookie, 'POST', ['csrf' => p6_csrf($preview['body'])]);
    p6_check($preview['status'] === 200 && $confirm['status'] === 403 && p6_session_row($live['session'])['status'] === 'active', 'a kiosk revoked between preview and confirmation cannot complete the exit');

    echo "\n{$GLOBALS['p6_checks']} Phase 6 HTTP flow checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n"); exit(1); }
