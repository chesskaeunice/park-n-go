<?php
declare(strict_types=1);

// Migration 008 validation on fresh *_test databases: rerun safety, constraints, append-only audit, and a 007 -> 008 upgrade (tests/run-phase7.php p7_migration).
require __DIR__ . '/fixtures/phase7-support.php';

use ParkNGo\Services\Database;

$pdo = Database::connection();
$rejects = static function (string $sql, array $params, string $label, string $contains = '') use ($pdo): void {
    try { $pdo->prepare($sql)->execute($params); } catch (PDOException $error) { p6_check($contains === '' || str_contains($error->getMessage(), $contains), $label . ' (SQLSTATE ' . $error->getCode() . ')'); return; }
    throw new RuntimeException('FAIL ' . $label . ' (row was accepted)');
};
$migrate = static function (array $env): array {
    $process = proc_open([PHP_BINARY, BASE_PATH . '/database/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $env);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
};
try {
    $files = glob(BASE_PATH . '/database/migrations/*.sql');
    [$code, $output] = $migrate(getenv());
    p6_check($code === 0 && substr_count($output, 'Already applied:') === count($files) && !str_contains($output, 'Applied: '), 'rerunning migrations is a no-op once 001-008 are applied');
    p6_check(hash_equals((string) p6_value('SELECT checksum FROM migrations WHERE version=?', ['008_phase7_admin_operations.sql']), hash_file('sha256', BASE_PATH . '/database/migrations/008_phase7_admin_operations.sql')), '008 checksum matches the recorded migration');
    foreach (['admin_permissions', 'admin_audit_events', 'parking_session_exceptions', 'refund_requests', 'reconciliation_actions'] as $table) p6_check((bool) p6_value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), "$table exists");
    $rateColumns = $pdo->query("SELECT COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parking_rates' AND COLUMN_NAME IN ('created_by','change_reason')")->fetchAll(PDO::FETCH_KEY_PAIR);
    p6_check($rateColumns === ['created_by' => 'YES', 'change_reason' => 'YES'], 'parking_rates gains only nullable provenance columns');
    foreach ([['payment_attempts', 'idx_payment_attempt_verified'], ['reservations', 'idx_reservation_created'], ['parking_sessions', 'idx_session_exit'], ['admin_audit_events', 'idx_admin_audit_facility']] as [$table, $index]) {
        p6_check((bool) p6_value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$table, $index]), "$table has index $index");
    }
    $types = $pdo->query("SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME),COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('payment_attempt_id','user_id','actor_id','facility_id','parking_session_id') AND TABLE_NAME IN ('refund_requests','admin_permissions','admin_audit_events','parking_session_exceptions','reconciliation_actions')")->fetchAll(PDO::FETCH_KEY_PAIR);
    p6_check($types && array_unique(array_values($types)) === ['bigint(20) unsigned'], 'new foreign key columns match the BIGINT UNSIGNED parent keys');

    // Fixture rows.
    $place = p6_facility(); $admin = p7_admin(); $payment = p7_payment($place, 'INITIAL_RESERVATION', 5000, p6_utc()); $s = p6_session($place);
    $key = static fn (): string => bin2hex(random_bytes(32));
    $refund = 'INSERT INTO refund_requests (reference,payment_attempt_id,amount_centavos,currency,status,request_reason,requested_by,idempotency_key) VALUES (?,?,?,?,?,?,?,?)';
    $rejects($refund, ['RFD-A', $payment['id'], 100, 'PHP', 'refunded', 'Invalid status', $admin['id'], $key()], 'refund status outside the lifecycle is rejected', 'chk_refund_status');
    $rejects($refund, ['RFD-B', $payment['id'], 0, 'PHP', 'requested', 'Zero refund', $admin['id'], $key()], 'zero refund amount is rejected', 'chk_refund_amount');
    $rejects($refund, ['RFD-C', $payment['id'], 100, 'USD', 'requested', 'Wrong currency', $admin['id'], $key()], 'non-PHP refund currency is rejected', 'chk_refund_currency');
    $rejects($refund, ['RFD-D', $payment['id'], 100, 'PHP', 'provider_confirmed', 'Confirmed without evidence', $admin['id'], $key()], 'a confirmed refund without provider evidence is rejected', 'chk_refund_confirmed');
    $rejects($refund, ['RFD-E', 999999999, 100, 'PHP', 'requested', 'Unknown payment', $admin['id'], $key()], 'a refund for an unknown payment is rejected by the foreign key', 'foreign key');
    $shared = $key(); $pdo->prepare($refund)->execute(['RFD-F', $payment['id'], 100, 'PHP', 'requested', 'First', $admin['id'], $shared]);
    $rejects($refund, ['RFD-G', $payment['id'], 100, 'PHP', 'requested', 'Same form twice', $admin['id'], $shared], 'a refund idempotency key is unique', 'Duplicate');
    $pdo->prepare("UPDATE refund_requests SET provider_refund_id='rfd-unique' WHERE reference='RFD-F'")->execute();
    $pdo->prepare($refund)->execute(['RFD-H', $payment['id'], 100, 'PHP', 'requested', 'Second', $admin['id'], $key()]);
    $rejects("UPDATE refund_requests SET provider_refund_id='rfd-unique' WHERE reference='RFD-H'", [], 'a provider refund id links to one request only', 'Duplicate');

    $audit = 'INSERT INTO admin_audit_events (actor_id,action,target_type,outcome,correlation_id,before_state) VALUES (?,?,?,?,?,?)';
    $rejects($audit, [$admin['id'], 'test', 'x', 'maybe', str_repeat('a', 32), null], 'audit outcome is limited to succeeded, denied, failed', 'chk_admin_audit_outcome');
    $rejects($audit, [$admin['id'], 'test', 'x', 'succeeded', str_repeat('a', 32), '{not json'], 'audit state must be valid JSON', 'chk_admin_audit_before');
    $rejects($audit, [999999999, 'test', 'x', 'succeeded', str_repeat('a', 32), null], 'audit actor must exist', 'foreign key');
    $pdo->prepare($audit)->execute([$admin['id'], 'test.append_only', 'x', 'succeeded', str_repeat('b', 32), '{"a":1}']);
    $rejects("UPDATE admin_audit_events SET reason='tampered' WHERE action='test.append_only'", [], 'admin audit events cannot be updated', 'append-only');
    $rejects("DELETE FROM admin_audit_events WHERE action='test.append_only'", [], 'admin audit events cannot be deleted', 'append-only');
    $pdo->prepare("INSERT INTO reconciliation_actions (case_key,payment_attempt_id,action,fingerprint,note,actor_id) VALUES ('attempt:1',?,'note',?,'n',?)")->execute([$payment['id'], str_repeat('c', 64), $admin['id']]);
    $rejects("DELETE FROM reconciliation_actions", [], 'reconciliation actions cannot be deleted', 'append-only');
    $rejects("INSERT INTO reconciliation_actions (case_key,action,fingerprint,note,actor_id) VALUES ('attempt:1','resolve_paid',?,'n',?)", [str_repeat('c', 64), $admin['id']], 'reconciliation actions cannot mark payments paid', 'chk_reconciliation_action');

    $exception = 'INSERT INTO parking_session_exceptions (parking_session_id,facility_id,category,reason,closed_by,closed_at,paid_until_at_close,chargeable_from,outstanding_seconds,obligation_status) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?)';
    $rejects($exception, [$s['session'], $place['facility'], 'fee_waiver', 'Not a valid category', $admin['id'], 0, 'none'], 'session exception categories exclude waivers', 'chk_session_exception_category');
    $rejects($exception, [$s['session'], $place['facility'], 'emergency_closure', 'Unsettled with nothing owed', $admin['id'], 0, 'unsettled'], 'an unsettled obligation must have outstanding time', 'chk_session_exception_outstanding');
    $rejects($exception, [$s['session'], $place['facility'], 'emergency_closure', 'Owed time hidden as none', $admin['id'], 600, 'none'], 'outstanding time cannot be recorded as no obligation', 'chk_session_exception_outstanding');
    $pdo->prepare($exception)->execute([$s['session'], $place['facility'], 'emergency_closure', 'Valid closure', $admin['id'], 0, 'none']);
    $rejects($exception, [$s['session'], $place['facility'], 'emergency_closure', 'Second closure', $admin['id'], 0, 'none'], 'one exception per parking session', 'Duplicate');

    // Upgrade rehearsal: a populated 007 database gains 008 without changing existing rows.
    $name = 'parkngo_p7_upgrade_' . bin2hex(random_bytes(4)) . '_test';
    $server = Database::connect(false);
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $old = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $name . ';charset=utf8mb4', getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $old->exec("SET time_zone='+00:00'");
        $old->exec('CREATE TABLE migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        foreach (array_slice($files, 0, 7) as $file) {
            foreach (explode(';', file_get_contents($file)) as $sql) if (trim($sql) !== '') $old->exec($sql);
            $old->prepare('INSERT INTO migrations (version,checksum) VALUES (?,?)')->execute([basename($file), hash_file('sha256', $file)]);
        }
        $old->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('Legacy Admin','legacy-admin@parkngo.test','x','admin'),('Legacy Driver','legacy-driver@parkngo.test','x','driver')");
        $old->exec("INSERT INTO parking_facilities (name,address,city) VALUES ('Legacy Lot','Address','City')");
        $old->exec("INSERT INTO parking_floors (facility_id,label) VALUES (1,'G')"); $old->exec("INSERT INTO parking_slots (floor_id,label) VALUES (1,'A-1')");
        $old->exec("INSERT INTO parking_rates (facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES (1,5000,2000,2500,'2026-01-01 00:00:00')");
        $old->exec("INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,status,estimated_centavos,rate_snapshot) VALUES ('PNG-LEGACY',2,1,1,'2026-02-01 01:00:00','2026-02-01 02:00:00','completed',5000,'{}')");
        $old->exec("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,maya_checkout_id,maya_request_reference,local_status,verified_at) VALUES ('PNGO-LEGACY',2,1,'INITIAL_RESERVATION',5000,'legacy-checkout','PNGO-LEGACY','verified','2026-02-01 00:30:00')");
        $old->exec("INSERT INTO audit_logs (actor_id,action) VALUES (1,'legacy.action')");
        $tables = $old->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $checksum = static fn (PDO $db, array $names): array => $db->query('CHECKSUM TABLE ' . implode(',', array_map(static fn (string $t): string => "`$t`", $names)) . ' EXTENDED')->fetchAll(PDO::FETCH_KEY_PAIR);
        $unchanged = array_values(array_diff($tables, ['migrations', 'parking_rates', 'payment_attempts', 'reservations', 'parking_sessions']));
        $before = $checksum($old, $unchanged);
        $rowsBefore = array_map(static fn (string $t): int => (int) $old->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(), array_combine($tables, $tables));
        $data = static fn (PDO $db): array => [$db->query('SELECT id,facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from FROM parking_rates')->fetchAll(PDO::FETCH_ASSOC), $db->query('SELECT * FROM payment_attempts')->fetchAll(PDO::FETCH_ASSOC), $db->query('SELECT * FROM reservations')->fetchAll(PDO::FETCH_ASSOC)];
        $dataBefore = $data($old);
        [$code, $output] = $migrate(array_merge(getenv(), ['DB_DATABASE' => $name]));
        p6_check($code === 0 && substr_count($output, 'Already applied:') === 7 && substr_count($output, 'Applied: ') === 1 && str_contains($output, 'Applied: 008_phase7_admin_operations.sql'), 'a populated 007 database applies only 008');
        p6_check($checksum($old, $unchanged) === $before, 'tables 008 does not alter keep identical checksums');
        p6_check(array_map(static fn (string $t): int => (int) $old->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(), array_combine($tables, $tables)) === array_replace($rowsBefore, ['migrations' => 8]) && $data($old) === $dataBefore, 'existing rows and values are preserved by the upgrade');
        p6_check($old->query('SELECT created_by IS NULL AND change_reason IS NULL FROM parking_rates')->fetchColumn() == 1, 'existing rates keep null provenance rather than invented values');
        [$code, $output] = $migrate(array_merge(getenv(), ['DB_DATABASE' => $name]));
        p6_check($code === 0 && !str_contains($output, 'Applied: '), 'rerunning the upgrade is a no-op');
    } finally { $server->exec("DROP DATABASE IF EXISTS `$name`"); }
    p6_check(!p6_value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$name]), 'the upgrade rehearsal database was dropped');

    $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    if (!str_contains($mode, 'STRICT_TRANS_TABLES') && !str_contains($mode, 'STRICT_ALL_TABLES')) echo "NOTE sql_mode is not strict ({$mode}). Phase 7 statuses use CHECK constraints, which are enforced regardless; enable STRICT_TRANS_TABLES for deployment.\n";
    echo "\n{$GLOBALS['p6_checks']} migration 008 checks passed.\n";
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
