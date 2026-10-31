<?php
declare(strict_types=1);

// Prospective facility pricing (tests/run-phase7.php p7_pricing).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\{Clock, Database};
use ParkNGo\Services\Admin\{PermissionDenied, PricingService};
use ParkNGo\Services\Parking\{AvailabilityWindow, OvertimeService, ReservationService};
use ParkNGo\Services\Payments\PaymentService;

$pdo = Database::connection(); $pricing = new PricingService();
$values = static fn (array $override = []): array => $override + ['initial_minutes' => '60', 'initial_centavos' => '70.00', 'succeeding_hour_centavos' => '35.00', 'overtime_hour_centavos' => '40.00', 'daily_max_centavos' => '', 'additional_centavos' => '0'];
$latest = static fn (int $facility): int => (int) p6_value('SELECT id FROM parking_rates WHERE facility_id=? ORDER BY effective_from DESC,id DESC LIMIT 1', [$facility]);
$manila = static fn (string $modify): string => (new DateTimeImmutable($modify, new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
try {
    $place = p6_facility(2000, 2500); $f = $place['facility'];
    $pricer = p7_admin(['manage_pricing']); $plain = p7_admin();

    // Snapshot the records a change must never touch: an active paid session, its settled overtime, a purchased extension, payments.
    $session = p6_session($place, null, '+20 minutes', '-40 minutes');
    $settled = p6_session($place, null, '+30 minutes', '-2 hours');
    $pdo->prepare("INSERT INTO parking_overtime_settlements (parking_session_id,interval_start,interval_end,billed_seconds,rate_centavos,amount_centavos,status,verified_at,grace_until) VALUES (?,?,?,600,2500,417,'verified',?,?)")->execute([$settled['session'], p6_utc('-70 minutes'), p6_utc('-60 minutes'), p6_utc('-60 minutes'), p6_utc('-45 minutes')]);
    $pdo->prepare("INSERT INTO parking_extensions (parking_session_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until,status,verified_at) VALUES (?,60,2000,2000,?,?,'verified',?)")->execute([$settled['session'], p6_utc('-30 minutes'), p6_utc('+30 minutes'), p6_utc('-35 minutes')]);
    $snapshot = static fn (): array => [
        p6_row('SELECT * FROM parking_rates WHERE id=?', [$place['rate']]),
        $pdo->query('SELECT id,rate_id,estimated_centavos,rate_snapshot FROM reservations ORDER BY id')->fetchAll(),
        $pdo->query('SELECT id,amount_centavos,local_status FROM payment_attempts ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM parking_overtime_settlements ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM parking_extensions ORDER BY id')->fetchAll(),
        $pdo->query('SELECT id,paid_until,purchased_minutes FROM parking_sessions ORDER BY id')->fetchAll(),
    ];
    $before = $snapshot();

    // Validation: integer centavos, no negatives, no sub-centavo precision, no invented fees.
    $invalid = [
        [['initial_centavos' => '-1'], 'pesos', 'negative rate'], [['succeeding_hour_centavos' => 'abc'], 'pesos', 'non-numeric rate'],
        [['initial_centavos' => '70.001'], 'two decimal places', 'sub-centavo precision'], [['initial_centavos' => '0'], 'between', 'zero initial rate'],
        [['overtime_hour_centavos' => '0'], 'between', 'zero overtime rate'], [['succeeding_hour_centavos' => ''], 'pesos', 'missing hourly rate'],
        [['daily_max_centavos' => '50.00'], 'daily maximum', 'daily maximum below the initial rate'], [['initial_minutes' => '5'], 'initial period', 'initial period under 15 minutes'],
        [['initial_centavos' => '100001'], 'between', 'rate above the supported maximum'],
    ];
    foreach ($invalid as [$override, $message, $label]) p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values($override), '', 'Quarterly review of rates', $place['rate']), "$label is rejected", $message);
    p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values(), $manila('-2 hours'), 'Backdated change attempt', $place['rate']), 'a backdated rate is rejected', 'backdated');
    p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values(), $manila('+400 days'), 'Too far ahead', $place['rate']), 'a rate more than a year ahead is rejected', 'one year');
    p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values(), '2026-13-40T99:99', 'Invalid date entered', $place['rate']), 'an invalid effective date is rejected', 'valid effective');
    p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values(), '', 'x', $place['rate']), 'a pricing change needs a reason', 'reason');
    p6_throws(static fn () => $pricing->schedule($plain['id'], $f, $values(), '', 'Unauthorized change attempt', $place['rate']), 'an admin without manage_pricing cannot change rates', '', PermissionDenied::class);
    p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values(), '', 'Stale form submission', $place['rate'] + 999), 'a change based on outdated rates is refused', 'changed while you were editing');
    p6_check($latest($f) === $place['rate'] && $snapshot() === $before, 'rejected changes wrote nothing');

    // A valid change takes effect now as a new version; the old row is untouched.
    $rate = $pricing->schedule($pricer['id'], $f, $values(), '', 'Annual rate adjustment approved by management', $place['rate']);
    $new = p6_row('SELECT * FROM parking_rates WHERE id=?', [$rate['id']]);
    p6_check((int) $new['initial_centavos'] === 7000 && (int) $new['succeeding_hour_centavos'] === 3500 && (int) $new['overtime_hour_centavos'] === 4000 && $new['daily_max_centavos'] === null && (int) $new['created_by'] === $pricer['id'], 'the new rate is stored in integer centavos with its author');
    p6_check((int) $new['grace_minutes'] === 15 && (int) $new['cancellation_cutoff_minutes'] === 60, 'grace and cancellation policy carry forward unchanged');
    p6_check(abs(strtotime($new['effective_from'] . ' UTC') - Clock::now()->getTimestamp()) <= 5, 'a blank effective time means now');
    p6_check($snapshot() === $before, 'existing rate row, reservations, payments, settlements, extensions, and sessions are unchanged');
    $event = p7_audit('pricing.rate_scheduled', $rate['id']);
    p6_check($event && json_decode((string) $event['before_state'], true)['id'] === $place['rate'] && json_decode((string) $event['after_state'], true)['initial_centavos'] === 7000 && $event['reason'] === 'Annual rate adjustment approved by management' && (int) $event['facility_id'] === $f, 'the change is audited with before/after values and reason');

    // Active paid sessions keep their snapshot: extension and overtime prices come from the reservation's rate.
    $sessionRow = p6_row('SELECT p.*,pr.overtime_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE p.id=?', [$session['session']]);
    p6_check((int) (new OvertimeService())->calculate($sessionRow, p6_utc('+80 minutes'))['rate_centavos'] === 2500, 'overtime for an existing session still uses its original rate');
    $url = (new PaymentService())->startExtensionPayment($session['driver']['id'], $session['session'], [2]);
    $attempt = p6_attempt_by_url($url);
    p6_check((int) $attempt['amount_centavos'] === 4000 && (int) p6_ledger($attempt)['rate_centavos'] === 2000, 'an extension bought after the change is priced at the session\'s original rate');

    // New bookings use the new rate.
    $local = (new DateTimeImmutable('+3 hours', new DateTimeZone('Asia/Manila'))); $window = new AvailabilityWindow(['date' => $local->format('Y-m-d'), 'time' => $local->format('H') . ':00', 'hours' => '2']);
    $quote = (new ReservationService())->quote($place['slot'], $window);
    p6_check((int) $quote['rate']['id'] === $rate['id'] && (int) $quote['estimate']['total_centavos'] === 7000 + 3500, 'a new reservation quote uses the new rate');

    // Scheduled future rates apply only to bookings starting on or after their effective time.
    $future = $pricing->schedule($pricer['id'], $f, $values(['initial_centavos' => '80.00']), $manila('+2 days'), 'Holiday season pricing', $rate['id']);
    p6_check($future['effective_from'] > p6_utc('+1 day'), 'a future effective time is honoured');
    p6_check((int) (new ReservationService())->quote($place['slot'], $window)['rate']['id'] === $rate['id'], 'bookings before the scheduled time keep the current rate');
    $later = (new DateTimeImmutable('+3 days', new DateTimeZone('Asia/Manila')));
    p6_check((int) (new ReservationService())->quote($place['slot'], new AvailabilityWindow(['date' => $later->format('Y-m-d'), 'time' => '10:00', 'hours' => '1']))['rate']['id'] === $future['id'], 'bookings after the scheduled time use the scheduled rate');
    p6_throws(static fn () => $pricing->schedule($pricer['id'], $f, $values(), $manila('+1 day'), 'Sneaking in before the scheduled rate', $future['id']), 'a new rate cannot be slipped in before an already scheduled one', 'after the latest scheduled');

    // HTTP.
    $cookie = p6_login($pricer);
    $page = p6_http('page=admin-pricing&facility_id=' . $f, $cookie);
    p6_check($page['status'] === 200 && str_contains($page['body'], '₱70.00') && str_contains($page['body'], 'Scheduled: ₱80.00'), 'the pricing page shows the current and scheduled rates');
    preg_match('/name="expected_latest" value="(\d+)"/', $page['body'], $expected);
    $bad = p6_http('page=admin-pricing&facility_id=' . $f, $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'expected_latest' => $expected[1], 'reason' => 'Negative test over HTTP', 'effective_from' => ''] + $values(['initial_centavos' => '-3']));
    p6_check($bad['status'] === 422 && str_contains(p6_errors($bad['body']), 'pesos'), 'an invalid HTTP rate change returns 422 with a message');
    $good = p6_http('page=admin-pricing&facility_id=' . $f, $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'expected_latest' => $expected[1], 'reason' => 'After-holiday pricing over HTTP', 'effective_from' => $manila('+5 days')] + $values(['initial_centavos' => '75.50']));
    p6_check($good['status'] === 303 && (int) p6_value('SELECT initial_centavos FROM parking_rates WHERE id=?', [$latest($f)]) === 7550, 'a valid HTTP rate change is scheduled');
    p6_check(p6_http('page=admin-pricing&facility_id=' . $f, $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'expected_latest' => $expected[1], 'reason' => 'Replaying the outdated form', 'effective_from' => $manila('+6 days')] + $values())['status'] === 422, 'replaying an outdated pricing form is refused');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 pricing checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
