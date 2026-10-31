<?php
declare(strict_types=1);

// Migration 007 validation on a fresh *_test database: rerun safety, checksums, and ledger constraints (tests/run-phase6.php migration).
require __DIR__ . '/fixtures/phase6-support.php';

use ParkNGo\Services\Database;

$pdo = Database::connection();
$rejects = static function (string $sql, array $params, string $label) use ($pdo): void {
    try { $pdo->prepare($sql)->execute($params); } catch (PDOException $error) { p6_check(true, $label . ' (SQLSTATE ' . $error->getCode() . ')'); return; }
    throw new RuntimeException('FAIL ' . $label . ' (row was accepted)');
};

try {
    $rerun = proc_open([PHP_BINARY, BASE_PATH . '/database/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $migrationCount = count(glob(BASE_PATH . '/database/migrations/*.sql'));
    p6_check(proc_close($rerun) === 0 && substr_count($output, 'Already applied:') === $migrationCount && !str_contains($output, 'Applied: '), 'rerunning migrations is a no-op once every migration is applied');
    foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $file) {
        p6_check(hash_equals((string) p6_value('SELECT checksum FROM migrations WHERE version=?', [basename($file)]), hash_file('sha256', $file)), basename($file) . ' checksum matches the recorded migration');
    }
    foreach (['parking_overtime_settlements' => ['interval_start', 'interval_end', 'billed_seconds', 'rate_centavos', 'amount_centavos', 'grace_until', 'verified_at'], 'parking_extensions' => ['duration_minutes', 'amount_centavos', 'rate_centavos', 'paid_until_before', 'requested_paid_until', 'verified_at']] as $table => $columns) {
        $have = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
        p6_check(array_diff($columns, $have) === [], "$table has the expected ledger columns");
    }
    $place = p6_facility(); $s = p6_session($place);
    $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_request_reference) VALUES (?,?,?,'PARKING_EXTENSION',2000,'PHP',?)")->execute([$ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))), $s['driver']['id'], $s['session'], $ref]);
    $attempt = (int) $pdo->lastInsertId();
    $ext = 'INSERT INTO parking_extensions (parking_session_id,payment_attempt_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until) VALUES (?,?,?,?,?,?,?)';
    $rejects($ext, [$s['session'], null, 90, 2000, 2000, '2026-01-01 10:00:00', '2026-01-01 11:30:00'], 'extension duration outside 1-6 whole hours is rejected by the schema');
    $rejects($ext, [$s['session'], null, 420, 14000, 2000, '2026-01-01 10:00:00', '2026-01-01 17:00:00'], 'extension above six hours is rejected by the schema');
    $rejects($ext, [$s['session'], null, 60, 0, 2000, '2026-01-01 10:00:00', '2026-01-01 11:00:00'], 'zero-amount extension is rejected');
    $rejects($ext, [$s['session'], null, 60, 2000, 2000, '2026-01-01 10:00:00', '2026-01-01 10:00:00'], 'extension that does not move paid-until forward is rejected');
    $rejects($ext, [999999999, null, 60, 2000, 2000, '2026-01-01 10:00:00', '2026-01-01 11:00:00'], 'extension for an unknown session is rejected by the foreign key');
    $pdo->prepare($ext)->execute([$s['session'], $attempt, 60, 2000, 2000, '2026-01-01 10:00:00', '2026-01-01 11:00:00']);
    $rejects($ext, [$s['session'], $attempt, 60, 2000, 2000, '2026-01-01 11:00:00', '2026-01-01 12:00:00'], 'one payment attempt can fund only one extension');
    $ot = 'INSERT INTO parking_overtime_settlements (parking_session_id,payment_attempt_id,interval_start,interval_end,billed_seconds,rate_centavos,amount_centavos) VALUES (?,?,?,?,?,?,?)';
    $rejects($ot, [$s['session'], null, '2026-01-01 10:00:00', '2026-01-01 10:00:00', 1, 2500, 1], 'empty overtime interval is rejected');
    $rejects($ot, [$s['session'], null, '2026-01-01 10:00:00', '2026-01-01 10:00:01', 0, 2500, 1], 'zero billed seconds is rejected');
    $rejects($ot, [$s['session'], null, '2026-01-01 10:00:00', '2026-01-01 10:00:01', 1, 2500, 0], 'zero-amount overtime settlement is rejected');
    foreach (['parking_extensions', 'parking_overtime_settlements'] as $table) {
        $type = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME='status'")->fetchColumn();
        p6_check($type === "enum('pending','verified','reconciliation','failed','expired','cancelled')", "$table status is limited to the approved ledger states");
    }
    $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    if (!str_contains($mode, 'STRICT_TRANS_TABLES') && !str_contains($mode, 'STRICT_ALL_TABLES')) echo "NOTE sql_mode is not strict ({$mode}); invalid ENUM writes would be stored as '' instead of rejected. Enable STRICT_TRANS_TABLES for deployment.\n";
    echo "\n{$GLOBALS['p6_checks']} migration 007 checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
