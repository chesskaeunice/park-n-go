<?php
declare(strict_types=1);

// Overtime and 15-minute grace boundaries through the real kiosk and driver HTTP endpoints, using a
// controlled server clock (tests/fixtures/clock-router.php). Run via: php tests/run-phase6.php grace
require __DIR__ . '/fixtures/phase6-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Parking\OvertimeService;
use ParkNGo\Services\Payments\PaymentService;

$pdo = Database::connection();
$at = static fn(string $utc, int $seconds): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
$ledgers = static fn(int $session): array => (function () use ($pdo, $session): array { $find = $pdo->prepare('SELECT * FROM parking_overtime_settlements WHERE parking_session_id=? ORDER BY id'); $find->execute([$session]); return $find->fetchAll(); })();

/** Settle overtime through HTTP: dashboard POST, Maya success, then server-side verification at $verifyAt. */
$settle = static function (string $driverCookie, array $s, string $createAt, string $verifyAt) use ($pdo): array {
    p6_clock($createAt);
    $dash = p6_http('page=dashboard', $driverCookie);
    $start = p6_http('page=overtime-start', $driverCookie, 'POST', ['csrf' => p6_csrf($dash['body']), 'session_id' => $s['session']]);
    if ($start['status'] !== 303 || !str_starts_with((string) $start['location'], 'https://fake-maya.invalid/')) throw new RuntimeException('Overtime checkout did not redirect to Maya: ' . $start['status'] . ' ' . p6_errors(p6_http('page=dashboard', $driverCookie)['body']));
    $attempt = p6_attempt_by_url((string) $start['location']);
    p6_fake_status((string) $attempt['maya_checkout_id'], 'PAYMENT_SUCCESS');
    p6_clock($verifyAt);
    $return = p6_http('page=payment-return&reference=' . $attempt['reference'], $driverCookie);
    if (!str_contains($return['body'], 'Overtime payment verified')) throw new RuntimeException('Overtime payment was not verified server-side.');
    return [$attempt, p6_ledger($attempt)];
};

try {
    $place = p6_facility(2000, 2500);
    $kiosk = p6_kiosk($place['facility']);

    // --- Arithmetic boundaries (integer centavos, elapsed seconds, round up once) ---
    $calc = static fn(string $now, int $rate = 2500, ?string $grace = null): array => (new OvertimeService())->calculate(['paid_until' => '2026-03-01 08:00:00', 'overtime_hour_centavos' => $rate, 'grace_until' => $grace], $now);
    foreach ([['2026-03-01 08:00:00', 0, 0, false], ['2026-03-01 08:00:01', 1, 1, true], ['2026-03-01 08:00:30', 30, 21, true], ['2026-03-01 08:01:00', 60, 42, true], ['2026-03-01 08:01:01', 61, 43, true], ['2026-03-01 09:00:00', 3600, 2500, true], ['2026-03-01 10:30:00', 9000, 6250, true], ['2026-03-01 11:00:01', 10801, 7501, true]] as [$now, $seconds, $centavos, $required]) {
        $state = $calc($now);
        p6_check($state['elapsed_seconds'] === $seconds && $state['estimated_centavos'] === $centavos && is_int($state['estimated_centavos']) && $state['payment_required'] === $required, "overtime at +{$seconds}s charges {$centavos} centavos");
    }
    p6_check($calc('2026-03-01 08:00:07', 3600)['estimated_centavos'] === 7 && $calc('2026-03-01 08:00:01', 1)['estimated_centavos'] === 1, 'proration is exact when divisible and rounds the total up to the next centavo otherwise');
    p6_check($calc('2026-03-01 08:20:00', 2500, '2026-03-01 08:15:00')['elapsed_seconds'] === 300 && $calc('2026-03-01 08:20:00', 2500, '2026-03-01 08:15:00')['chargeable_from'] === '2026-03-01 08:15:00', 'after grace only newly accrued time is chargeable');

    // --- Exactly at, and one second after, paid-until through the kiosk ---
    $a1 = p6_session($place, null, '-3 hours', '-4 hours'); $t1 = p6_session_row($a1['session'])['paid_until'];
    p6_clock($t1); [$verify, $confirm, $body] = p6_kiosk_exit($kiosk, $a1['manual']);
    p6_check($verify === 200 && $confirm === 200 && str_contains($body, 'Exit confirmed') && p6_session_row($a1['session'])['status'] === 'completed', 'kiosk exit exactly at paid-until is allowed (no overtime yet)');
    $a2 = p6_session($place, null, '-3 hours', '-4 hours'); $t2 = p6_session_row($a2['session'])['paid_until']; $driver2 = p6_login($a2['driver']);
    p6_clock($at($t2, 1)); [$verify, , $body] = p6_kiosk_exit($kiosk, $a2['manual']);
    p6_check($verify === 422 && str_contains($body, 'unpaid overtime') && p6_session_row($a2['session'])['status'] === 'active', 'kiosk denies exit one second after paid-until');
    p6_check(str_contains(p6_http('page=dashboard', $driver2)['body'], 'Overtime due: ₱0.01'), 'dashboard shows the one-second overtime as one centavo');

    // --- Settlement, redirect is not proof, grace boundaries, duplicate webhook, new overtime, no double billing ---
    $b = p6_session($place, null, '-3 hours', '-4 hours'); $t = p6_session_row($b['session'])['paid_until']; $driver = p6_login($b['driver']);
    p6_clock($at($t, 1800));
    $dash = p6_http('page=dashboard', $driver);
    p6_check(str_contains($dash['body'], 'Overtime due: ₱12.50') && str_contains($dash['body'], 'Pay overtime') && !str_contains($dash['body'], 'Pay extension'), 'dashboard offers overtime settlement (30 min at 25.00/h) and hides extension while overdue');
    $start = p6_http('page=overtime-start', $driver, 'POST', ['csrf' => p6_csrf($dash['body']), 'session_id' => $b['session']]);
    $attempt = p6_attempt_by_url((string) $start['location']); $ledger = p6_ledger($attempt);
    p6_check($ledger['interval_start'] === $t && $ledger['interval_end'] === $at($t, 1800) && (int) $ledger['billed_seconds'] === 1800 && (int) $ledger['amount_centavos'] === 1250 && (int) $attempt['amount_centavos'] === 1250, 'overtime checkout bills the exact elapsed interval in integer centavos');
    $return = p6_http('page=payment-return&reference=' . $attempt['reference'], $driver);
    [$verify] = p6_kiosk_exit($kiosk, $b['manual']);
    p6_check(str_contains($return['body'], 'awaiting independent server verification') && p6_ledger($attempt)['status'] === 'pending' && $verify === 422, 'Maya redirect alone is not proof: pending provider status keeps exit blocked [return ' . $return['status'] . ', kiosk ' . $verify . ']');
    p6_fake_status((string) $attempt['maya_checkout_id'], 'PAYMENT_SUCCESS');
    $v = $at($t, 1920); p6_clock($v);
    $refresh = p6_http('page=payment-refresh', $driver, 'POST', ['csrf' => p6_csrf($return['body']), 'reference' => $attempt['reference']]);
    $page = p6_http('page=payment-return&checked=1&reference=' . $attempt['reference'], $driver);
    $settled = p6_ledger($attempt);
    p6_check($refresh['status'] === 303 && str_contains($page['body'], 'Overtime payment verified') && $settled['status'] === 'verified' && $settled['verified_at'] === $v && $settled['grace_until'] === $at($v, 900), 'Refresh payment status verifies server-side and starts 15-minute grace at the verification timestamp');
    (new PaymentService())->handleVerifiedWebhook(p6_webhook_payload($attempt));
    p6_clock($at($v, 600)); p6_http('page=payment-return&reference=' . $attempt['reference'], $driver);
    p6_check(p6_ledger($attempt)['grace_until'] === $at($v, 900) && count($ledgers($b['session'])) === 1, 'duplicate webhook and later verification do not renew grace');
    $dash = p6_http('page=dashboard', $driver);
    p6_check(str_contains($dash['body'], 'Overtime settled. Exit before') && str_contains($dash['body'], 'Pay extension'), 'dashboard shows the exit grace and allows extension during grace');
    $again = p6_http('page=overtime-start', $driver, 'POST', ['csrf' => p6_csrf($dash['body']), 'session_id' => $b['session']]);
    p6_check($again['status'] === 303 && str_contains((string) $again['location'], 'page=dashboard') && str_contains(p6_errors(p6_http('page=dashboard', $driver)['body']), 'no payable overtime'), 'repeated settlement during grace is refused with a visible message');
    p6_clock($at($v, 899));
    $home = p6_http('page=kiosk-home', $kiosk); $verifyBefore = p6_http('page=kiosk-verify', $kiosk, 'POST', ['csrf' => p6_csrf($home['body']), 'code' => $b['manual']]);
    p6_check($verifyBefore['status'] === 200 && str_contains($verifyBefore['body'], 'Confirm Exit'), 'kiosk accepts the ticket one second before grace expiry');
    p6_clock($at($v, 900)); $home = p6_http('page=kiosk-home', $kiosk); $verifyAt = p6_http('page=kiosk-verify', $kiosk, 'POST', ['csrf' => p6_csrf($home['body']), 'code' => $b['manual']]);
    p6_check($verifyAt['status'] === 200 && str_contains($verifyAt['body'], 'Confirm Exit'), 'kiosk accepts the ticket exactly at grace expiry (inclusive)');
    p6_clock($at($v, 901)); $confirm = p6_http('page=kiosk-exit', $kiosk, 'POST', ['csrf' => p6_csrf($verifyAt['body'])]);
    p6_check($confirm['status'] === 422 && str_contains($confirm['body'], 'Payment Required') && p6_session_row($b['session'])['status'] === 'active', 'confirmation one second after grace expiry is blocked even after a successful preview');
    [$verify, , $body] = p6_kiosk_exit($kiosk, $b['manual']);
    p6_check($verify === 422 && str_contains($body, 'unpaid overtime'), 'kiosk denies exit after grace expiry');
    [$second, $secondLedger] = $settle($driver, $b, $at($v, 1500), $at($v, 1560));
    $all = $ledgers($b['session']);
    p6_check($secondLedger['interval_start'] === $at($v, 900) && $secondLedger['interval_end'] === $at($v, 1500) && (int) $secondLedger['billed_seconds'] === 600 && (int) $secondLedger['amount_centavos'] === 417, 'new overtime after grace bills only the time after grace (10 min = 4.17)');
    p6_check($all[0]['interval_end'] <= $all[1]['interval_start'] && array_sum(array_map('intval', array_column($all, 'amount_centavos'))) === 1667, 'settled intervals never overlap, so no period is billed twice');
    p6_clock($at($v, 1560 + 900)); [$verify, $confirm, $body] = p6_kiosk_exit($kiosk, $b['manual']);
    p6_check($verify === 200 && $confirm === 200 && str_contains($body, 'Exit confirmed') && p6_session_row($b['session'])['status'] === 'completed', 'exit exactly at the second grace expiry completes the session');

    // --- Exit just before grace expiry ---
    $c = p6_session($place, null, '-3 hours', '-4 hours'); $tc = p6_session_row($c['session'])['paid_until']; $driverC = p6_login($c['driver']);
    [, $cl] = $settle($driverC, $c, $at($tc, 300), $at($tc, 360));
    p6_clock($at($cl['grace_until'], -1)); [$verify, $confirm, $body] = p6_kiosk_exit($kiosk, $c['token']);
    p6_check($verify === 200 && $confirm === 200 && str_contains($body, 'Exit confirmed'), 'exit one second before grace expiry succeeds with the QR token');

    // --- Extension supersedes the existing grace (approved time base: max(paid-until, now)) ---
    $d = p6_session($place, null, '-3 hours', '-4 hours'); $td = p6_session_row($d['session'])['paid_until']; $driverD = p6_login($d['driver']);
    [, $dl] = $settle($driverD, $d, $at($td, 600), $at($td, 660));
    $x = $at($dl['verified_at'], 300); p6_clock($x);
    $dash = p6_http('page=dashboard', $driverD);
    $ext = p6_http('page=extension-start', $driverD, 'POST', ['csrf' => p6_csrf($dash['body']), 'session_id' => $d['session'], 'blocks' => ['1']]);
    $extAttempt = p6_attempt_by_url((string) $ext['location']); $extLedger = p6_ledger($extAttempt);
    p6_check($extLedger['paid_until_before'] === $td && $extLedger['requested_paid_until'] === $at($x, 3600), 'extension during grace starts at server time and does not re-bill the settled interval');
    p6_fake_status((string) $extAttempt['maya_checkout_id'], 'PAYMENT_SUCCESS');
    $page = p6_http('page=payment-return&reference=' . $extAttempt['reference'], $driverD);
    p6_check(str_contains($page['body'], 'parking session has been extended') && p6_session_row($d['session'])['paid_until'] === $at($x, 3600), 'verified extension sets paid-until once');
    p6_clock($at($dl['grace_until'], 60)); $home = p6_http('page=kiosk-home', $kiosk); $after = p6_http('page=kiosk-verify', $kiosk, 'POST', ['csrf' => p6_csrf($home['body']), 'code' => $d['manual']]);
    p6_check($after['status'] === 200, 'after the superseded grace would have expired, the extension still covers exit');
    p6_clock($at($x, 3601)); [$verify] = p6_kiosk_exit($kiosk, $d['manual']);
    p6_check($verify === 422, 'one second after the extended paid-until, overtime applies again');
    p6_clock($at($x, 3600)); [$verify, $confirm, $body] = p6_kiosk_exit($kiosk, $d['manual']);
    p6_check($verify === 200 && $confirm === 200 && str_contains($body, 'Exit confirmed'), 'exit exactly at the extended paid-until succeeds with the same QR');

    p6_clock(null);
    echo "\n{$GLOBALS['p6_checks']} overtime and grace boundary checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n"); exit(1); }
