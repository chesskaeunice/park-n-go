<?php
declare(strict_types=1);

// Reports, financial totals, and CSV/PDF export parity (tests/run-phase7.php p7_reports).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\{ReconciliationService, ReportExport, ReportService};

$pdo = Database::connection(); $reports = new ReportService();
$build = static fn (string $type, array $query, ?int $page = null): array => (new ReportService())->build($type, ReportService::filters($query), $page);
$summary = static fn (array $report): array => array_column($report['summary'], 1, 0);
$set = static function (string $table, int $id, array $values) use ($pdo): void {
    $pdo->prepare("UPDATE $table SET " . implode(',', array_map(static fn (string $k): string => "$k=?", array_keys($values))) . ' WHERE id=?')->execute([...array_values($values), $id]);
};
// PDF text operands, unescaped, in drawing order.
$pdfText = static function (string $pdf): array {
    preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $pdf, $matches);
    return array_map(static fn (string $s): string => strtr($s, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']), $matches[1]);
};
try {
    // Facility A, with a spreadsheet-formula name to prove export sanitization; facility B for filters.
    $a = p6_facility(); $b = p6_facility();
    $set('parking_facilities', $a['facility'], ['name' => '=HYPERLINK("http://evil.invalid","Lot A")']);
    $range = ['from' => '2026-03-10', 'to' => '2026-03-11'];

    // Revenue: one row per verified payment attempt, dated by verification time in Manila.
    $initialA = p7_payment($a, 'INITIAL_RESERVATION', 5000, '2026-03-10 02:00:00');
    $overtimeA = p7_payment($a, 'OVERTIME_SETTLEMENT', 417, '2026-03-11 15:30:00');      // Manila 23:30 on the 11th: in range
    $extensionA = p7_payment($a, 'PARKING_EXTENSION', 2000, '2026-03-11 16:30:00');     // Manila 00:30 on the 12th: out of range
    $initialB = p7_payment($b, 'INITIAL_RESERVATION', 6000, '2026-03-10 05:00:00');
    $edge = p7_payment($a, 'INITIAL_RESERVATION', 900, '2026-03-09 15:59:59');           // Manila 23:59:59 on the 9th: out of range
    $pendingA = p7_payment($a, 'PARKING_EXTENSION', 2000, '2026-03-10 06:00:00', 'checkout_created', null); $set('payment_attempts', $pendingA['id'], ['created_at' => '2026-03-10 06:00:00']);
    $failedA = p7_payment($a, 'PARKING_EXTENSION', 2000, '2026-03-10 07:00:00', 'failed', 'PAYMENT_FAILED'); $set('payment_attempts', $failedA['id'], ['created_at' => '2026-03-10 07:00:00']);
    $heldA = p7_payment($a, 'PARKING_EXTENSION', 2000, '2026-03-10 08:00:00', 'reconciliation', 'PAYMENT_SUCCESS');
    $set('payment_attempts', $heldA['id'], ['created_at' => '2026-03-10 08:00:00', 'reconciliation_reason' => 'Provider confirmed payment after the checkout was no longer fulfillable.']);
    $requester = p7_admin(); $refundKey = static fn (): string => bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO refund_requests (reference,payment_attempt_id,amount_centavos,status,request_reason,requested_by,decided_by,decided_at,provider_refund_id,provider_status,provider_confirmed_at,idempotency_key) VALUES (?,?,1000,'provider_confirmed','Gate fault',?,?,?,?,'SUCCESS',?,?)")
        ->execute(['RFD-' . strtoupper(bin2hex(random_bytes(10))), $initialA['id'], $requester['id'], $requester['id'], '2026-03-11 02:00:00', 'rfd-' . bin2hex(random_bytes(6)), '2026-03-11 03:00:00', $refundKey()]);
    $pdo->prepare("INSERT INTO refund_requests (reference,payment_attempt_id,amount_centavos,status,request_reason,requested_by,decided_by,decided_at,idempotency_key) VALUES (?,?,500,'approved','Approved, awaiting Maya',?,?,'2026-03-11 04:00:00',?)")
        ->execute(['RFD-' . strtoupper(bin2hex(random_bytes(10))), $overtimeA['id'], $requester['id'], $requester['id'], $refundKey()]);

    $revenue = $build('revenue', $range + ['facility_id' => (string) $a['facility']]); $s = $summary($revenue);
    p6_check($s['Verified initial parking payments'] === 5000 && $s['Verified overtime settlements'] === 417 && $s['Verified extension payments'] === 0, 'verified receipts by type use Manila-day verification boundaries');
    p6_check($s['Gross verified receipts'] === 5417, 'gross verified receipts = initial + overtime + extension');
    p6_check($s['Provider-confirmed refunds'] === 1000 && $s['Net verified receipts'] === 4417, 'net receipts subtract only provider-confirmed refunds');
    p6_check($s['Provider-paid, held in reconciliation (not recognized)'] === 2000 && $s['Pending payments excluded'] === 1, 'reconciliation-held and pending payments are reported separately and not recognized');
    p6_check($s['Verified payments counted'] === 2 && count($revenue['rows']) === 3, 'each verified payment attempt is counted once although ledger rows exist (no double counting)');
    p6_check(!array_intersect([$pendingA['reference'], $failedA['reference'], $heldA['reference'], $edge['reference'], $extensionA['reference']], array_column($revenue['rows'], 'reference')), 'pending, failed, reconciliation-held, and out-of-range payments are excluded from rows');
    p6_check(array_sum(array_column($revenue['rows'], 'gross_centavos')) === 5417 && array_sum(array_column($revenue['rows'], 'refund_centavos')) === 1000, 'detail rows add up to the summary');
    $all = $summary($build('revenue', $range));
    p6_check($all['Verified initial parking payments'] === 11000 && $all['Gross verified receipts'] === 11417 && $all['Net verified receipts'] === 10417, 'the all-facilities report includes facility B');
    p6_check($summary($build('revenue', $range + ['facility_id' => (string) $b['facility']]))['Gross verified receipts'] === 6000, 'the facility filter isolates facility B');
    p6_check($summary($build('revenue', ['from' => '2026-03-10', 'to' => '2026-03-12', 'facility_id' => (string) $a['facility']]))['Verified extension payments'] === 2000, 'extending the range by one Manila day includes the payment verified at 00:30');
    p6_check($summary($build('revenue', ['from' => '2026-03-09', 'to' => '2026-03-09', 'facility_id' => (string) $a['facility']]))['Gross verified receipts'] === 900, 'a payment at 23:59:59 Manila belongs to that Manila day');

    // Reservations: event timestamps per Manila day.
    $driver = p6_user('driver'); $slot = $a; $mk = static function (string $created, string $status, array $extra = []) use ($driver, &$slot, $set): int {
        $id = p6_reservation($driver['id'], $slot, '2026-03-20 01:00:00', '2026-03-20 02:00:00', $status); $set('reservations', $id, ['created_at' => $created] + $extra); return $id;
    };
    $mk('2026-03-10 01:00:00', 'confirmed', ['confirmed_at' => '2026-03-10 01:05:00']);
    $mk('2026-03-10 03:00:00', 'cancelled', ['cancelled_at' => '2026-03-10 03:30:00']);
    $expired = $mk('2026-03-11 02:00:00', 'expired'); p6_hold($driver['id'], $slot, $expired, '2026-03-20 01:00:00', '2026-03-20 02:00:00', '2026-03-11 02:10:00');
    $mk('2026-03-11 04:00:00', 'no_show', ['no_show_at' => '2026-03-11 06:00:00']);
    $res = $build('reservations', $range + ['facility_id' => (string) $a['facility']]); $r = $summary($res);
    p6_check($r['Created reservations'] === 4 && $r['Confirmed reservations'] === 1 && $r['Cancelled reservations'] === 1 && $r['Expired holds'] === 1 && $r['Recorded no-shows'] === 1, 'reservation report counts created, confirmed, cancelled, expired holds, and no-shows');
    p6_check(array_column($res['rows'], null, 'date')['2026-03-10']['created'] === 2 && array_column($res['rows'], null, 'date')['2026-03-11']['no_shows'] === 1, 'reservation counts are grouped by Manila day');
    p6_check($summary($build('reservations', $range + ['facility_id' => (string) $b['facility']]))['Created reservations'] === 0, 'reservation report respects the facility filter');

    // Occupancy: entries, exits, and utilization within the period.
    $pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$a['floor'], 'OCC-1']); $slot['slot'] = (int) $pdo->lastInsertId();
    $visit = p6_reservation($driver['id'], $slot, '2026-03-10 02:00:00', '2026-03-10 04:00:00', 'completed');
    $pdo->prepare("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,exit_at,purchased_minutes,paid_until,status) VALUES (?,?,'2026-03-10 02:00:00','2026-03-10 04:00:00',120,'2026-03-10 04:00:00','completed')")->execute([$visit, $driver['id']]);
    $occ = $build('occupancy', $range + ['facility_id' => (string) $a['facility']]); $o = $summary($occ);
    $row = array_values(array_filter($occ['rows'], static fn (array $x): bool => $x['slot'] === 'OCC-1'))[0];
    p6_check($o['Entries'] === 1 && $o['Exits'] === 1 && $o['Completed sessions'] === 1, 'occupancy report counts entries, exits, and completed sessions in the period');
    p6_check($row['sessions'] === 1 && $row['occupied_seconds'] === 7200 && $row['utilization_bp'] === intdiv(7200 * 10000, 48 * 3600), 'slot utilization is occupied time over the period');
    $slots = (int) p6_value("SELECT COUNT(*) FROM parking_slots s JOIN parking_floors fl ON fl.id=s.floor_id WHERE fl.facility_id=? AND s.status<>'disabled'", [$a['facility']]);
    p6_check(count($occ['rows']) === $slots && $o['Facility utilization'] === intdiv(7200 * 10000, 48 * 3600 * $slots), 'facility utilization averages all in-service slots');
    p6_check($o['Active sessions (now)'] === (int) p6_value("SELECT COUNT(*) FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id WHERE p.status='active' AND fl.facility_id=?", [$a['facility']]), 'active sessions are a live facility snapshot');

    // Reconciliation report.
    $rec = $summary($build('reconciliation', $range + ['facility_id' => (string) $a['facility']]));
    p6_check($rec['Open reconciliation cases (now)'] === count((new ReconciliationService())->cases($a['facility'])) && $rec['Late payments'] === 1 && $rec['Payment failures'] === 1, 'reconciliation report counts open cases, failures, and late payments');

    // Filters are validated.
    foreach ([[['from' => '2026-03-12', 'to' => '2026-03-10'], 'on or after'], [['from' => '2025-01-01', 'to' => '2026-03-10'], '366'], [['from' => '2026-02-30', 'to' => '2026-03-10'], 'valid report dates'], [['facility_id' => 'abc'], 'valid facility']] as [$query, $message]) {
        p6_throws(static fn () => ReportService::filters($query), 'invalid report filters are rejected (' . $message . ')', $message);
    }

    // Screen, CSV, and PDF parity over HTTP.
    $admin = p7_admin(); $cookie = p6_login($admin);
    $query = 'type=revenue&from=2026-03-10&to=2026-03-11&facility_id=' . $a['facility'];
    $screen = p6_http('page=admin-reports&' . $query, $cookie);
    p6_check($screen['status'] === 200 && str_contains($screen['body'], '₱54.17') && str_contains($screen['body'], '₱44.17') && substr_count($screen['body'], $initialA['reference']) === 1 && str_contains($screen['body'], $overtimeA['reference']), 'the screen shows the same totals and rows');
    $csv = p6_http('page=admin-report-export&format=csv&' . $query, $cookie);
    p6_check($csv['status'] === 200 && str_contains($csv['headers'], 'text/csv') && str_contains($csv['headers'], 'attachment; filename="parkngo-revenue-2026-03-10-to-2026-03-11-facility-' . $a['facility'] . '.csv"'), 'CSV export downloads with a descriptive filename');
    $lines = array_values(array_map('str_getcsv', array_filter(explode("\n", substr($csv['body'], 3)), 'strlen')));
    $csvSummary = []; foreach ($lines as $line) if (count($line) === 2) $csvSummary[$line[0]] = $line[1];
    p6_check($lines[0][0] === 'Revenue report' && str_ends_with($lines[1][1], 'Asia/Manila') && $csvSummary['Gross verified receipts'] === '54.17' && $csvSummary['Net verified receipts'] === '44.17' && $csvSummary['Provider-confirmed refunds'] === '10.00', 'CSV summary matches the report');
    $header = array_search(['Verified / confirmed (Manila)', 'Reference', 'Type', 'Facility', 'Gross', 'Refund'], $lines, true);
    $csvRows = array_slice($lines, $header + 1);
    p6_check(count($csvRows) === count($revenue['rows']) && array_column($csvRows, 1) === array_column($revenue['rows'], 'reference') && array_column($csvRows, 4) === array_map(static fn (array $x): string => number_format($x['gross_centavos'] / 100, 2, '.', ''), $revenue['rows']), 'CSV rows match the report rows');
    p6_check($csvRows[0][0] === ReportService::format($revenue['rows'][0]['at'], 'datetime', 'csv') && $csvRows[0][2] === 'Initial parking', 'CSV dates are Manila time and types are labeled');
    p6_check($lines[2][1] === "'" . '=HYPERLINK("http://evil.invalid","Lot A")' && $csvRows[0][3] === "'" . '=HYPERLINK("http://evil.invalid","Lot A")', 'CSV neutralizes spreadsheet formulas in facility names');
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tx"] as $payload) p6_check(ReportExport::csvCell($payload)[0] === "'", 'CSV cell starting with ' . json_encode($payload[0]) . ' is neutralized');
    p6_check(ReportExport::csvCell('Lot A') === 'Lot A', 'ordinary text is unchanged');
    $pdf = p6_http('page=admin-report-export&format=pdf&' . $query, $cookie);
    $text = $pdfText($pdf['body']);
    p6_check($pdf['status'] === 200 && str_contains($pdf['headers'], 'application/pdf') && str_starts_with($pdf['body'], '%PDF-1.4') && str_ends_with(trim($pdf['body']), '%%EOF'), 'PDF export is a PDF document');
    p6_check(in_array('Revenue report', $text, true) && in_array('Gross verified receipts: PHP 54.17', $text, true) && in_array('Net verified receipts: PHP 44.17', $text, true) && in_array('Provider-confirmed refunds: PHP 10.00', $text, true), 'PDF summary matches the report');
    p6_check(in_array($initialA['reference'], $text, true) && in_array($overtimeA['reference'], $text, true) && count(array_filter($text, static fn (string $t): bool => str_starts_with($t, 'PNGO-') || str_starts_with($t, 'RFD-'))) === count($revenue['rows']), 'PDF rows match the report rows');
    preg_match('/startxref\n(\d+)/', $pdf['body'], $xref); preg_match_all('/(\d{10}) 00000 n /', substr($pdf['body'], (int) $xref[1]), $offsets);
    p6_check(array_reduce($offsets[1], static fn (bool $ok, string $o): bool => $ok && preg_match('/^\d+ 0 obj/', substr($pdf['body'], (int) $o, 12)) === 1, true), 'PDF cross-reference offsets point at their objects');
    foreach ([$csv['body'], $pdf['body'], $screen['body']] as $index => $body) p6_check(!str_contains($body, $initialA['checkout']) && !str_contains($body, (string) getenv('FAKE_MAYA_SECRET_KEY') ?: 'sk-') , ['CSV', 'PDF', 'screen'][$index] . ' output contains no provider identifiers or secrets');

    // Large reports: PDF paginates with a repeated header; screen paginates; CSV keeps every row.
    $c = p6_facility(); $bulk = p7_payment($c, 'INITIAL_RESERVATION', 100, '2026-04-01 01:00:00');
    $insert = $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,provider_status,verified_at) VALUES (?,?,?,'INITIAL_RESERVATION',100,'PHP',?,?,'verified','PAYMENT_SUCCESS',?)");
    for ($i = 1; $i < 120; $i++) { $ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))); $insert->execute([$ref, $bulk['session']['driver']['id'], $bulk['session']['reservation'], 'fake-' . bin2hex(random_bytes(12)), $ref, '2026-04-01 01:' . str_pad((string) intdiv($i, 2), 2, '0', STR_PAD_LEFT) . ':00']); }
    $bigQuery = 'type=revenue&from=2026-04-01&to=2026-04-01&facility_id=' . $c['facility'];
    $big = p6_http('page=admin-report-export&format=pdf&' . $bigQuery, $cookie)['body'];
    preg_match('/\/Count (\d+)/', $big, $count); $bigText = $pdfText($big);
    p6_check((int) $count[1] >= 3 && in_array('Page 1 of ' . $count[1], $bigText, true) && in_array('Page ' . $count[1] . ' of ' . $count[1], $bigText, true), 'a long PDF paginates with page numbers');
    p6_check(count(array_keys($bigText, 'Reference', true)) === (int) $count[1] && count(array_filter($bigText, static fn (string $t): bool => str_starts_with($t, 'PNGO-'))) === 120, 'every PDF page repeats the table header and all rows are present');
    p6_check(substr_count(p6_http('page=admin-report-export&format=csv&' . $bigQuery, $cookie)['body'], 'PNGO-') === 120, 'CSV contains all 120 rows');
    $page2 = p6_http('page=admin-reports&p=3&' . $bigQuery, $cookie);
    p6_check(substr_count($page2['body'], 'PNGO-') === 20 && str_contains($page2['body'], 'Page 3 of 3'), 'the on-screen report is paginated');
    p6_check(p6_http('page=admin-report-export&format=xls&' . $bigQuery, $cookie)['status'] === 422 && p6_http('page=admin-report-export&format=csv&type=revenue&from=2026-04-02&to=2026-04-01', $cookie)['status'] === 422, 'invalid export formats and dates are rejected');
    p6_check(p7_audit('report.exported') && json_decode((string) p7_audit('report.exported')['after_state'], true)['type'] === 'revenue', 'exports are audited');

    echo "\n{$GLOBALS['p6_checks']} Phase 7 report checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
