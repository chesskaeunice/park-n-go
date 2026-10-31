<?php
declare(strict_types=1);

// CLI helper for tests/phase7-browser.mjs. Prints JSON only; runs against the same isolated *_test database.
ob_start();
require __DIR__ . '/phase7-support.php';
ob_end_clean();

$command = $argv[1] ?? '';
$out = static function (array $data): never { echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); exit(0); };
if ($command === 'setup') {
    $place = p6_facility(2000, 2500);
    $admin = p7_admin(['manage_pricing', 'approve_refunds', 'active_session_exception', 'manage_permissions']); $plain = p7_admin();
    $occupied = p6_session($place); $overdue = p6_session($place, null, '-12 minutes', '-72 minutes');
    $payment = p7_payment($place, 'INITIAL_RESERVATION', 5000, p6_utc());
    $out(['admin' => $admin, 'plain' => $plain, 'facility' => $place['facility'], 'idleSlot' => $place['slot'], 'overdueSession' => $overdue['session'], 'payment' => $payment['reference'], 'checkout' => $payment['checkout'],
        'activeSessions' => (int) p6_value("SELECT COUNT(*) FROM parking_sessions WHERE status='active'"), 'rate' => $place['rate']]);
}
if ($command === 'slot') $out(['status' => p6_value('SELECT status FROM parking_slots WHERE id=?', [(int) $argv[2]])]);
if ($command === 'session') $out(['status' => p6_value('SELECT status FROM parking_sessions WHERE id=?', [(int) $argv[2]]), 'obligation' => p6_value('SELECT obligation_status FROM parking_session_exceptions WHERE parking_session_id=?', [(int) $argv[2]])]);
if ($command === 'refund') $out(p6_row('SELECT id,reference,status,provider_refund_id,amount_centavos FROM refund_requests rr WHERE payment_attempt_id=(SELECT id FROM payment_attempts WHERE reference=?) ORDER BY id DESC LIMIT 1', [(string) $argv[2]]) ?: []);
if ($command === 'provider-refund') { p7_fake_refund((string) $argv[2], (string) $argv[3], (int) $argv[4], (string) $argv[5]); $out(['ok' => true]); }
if ($command === 'rate') $out(p6_row('SELECT id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,created_by FROM parking_rates WHERE facility_id=? ORDER BY effective_from DESC,id DESC LIMIT 1', [(int) $argv[2]]));
fwrite(STDERR, "Unknown command.\n"); exit(1);
