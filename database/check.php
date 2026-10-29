<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\{Database, Environment};

// Read-only inspection: no schema changes, account data, or credentials in output.
$expected = ['users','parking_facilities','parking_floors','parking_slots','reservations','reservation_holds','parking_sessions','qr_tickets','qr_scan_logs','parking_rates','payment_transactions','payment_events','receipts','notifications','audit_logs','password_reset_tokens','rate_limits','migrations'];
try {
    $pdo = Database::connection();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($expected, $tables));
    $versions = in_array('migrations', $tables, true) ? $pdo->query('SELECT version, checksum FROM migrations ORDER BY version')->fetchAll() : [];
    $result = ['database' => Environment::get('DB_DATABASE', 'parkngo_db'), 'connected' => true, 'missing_tables' => $missing, 'migrations' => $versions];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($missing ? 1 : 0);
} catch (PDOException $error) {
    echo json_encode(['connected' => false, 'message' => 'Database unavailable. Start MySQL and check private .env settings; run migrations if the database is not installed.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
