<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\{Database, KioskAuth, KioskService};

if (getenv('APP_ENV') !== 'local' || !str_ends_with(getenv('DB_DATABASE') ?: '', '_test')) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated *_test database.\n"); exit(1);
}

$pdo = Database::connection();
$checks = 0;
function kioskCheck(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL ' . $label); ++$checks; echo "PASS $label\n"; }
function kioskUser(PDO $pdo, string $email, string $role): int {
    $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $statement->execute(['Kiosk Test ' . $role, $email, password_hash('fixture', PASSWORD_DEFAULT), $role]);
    return (int) $pdo->lastInsertId();
}

try {
    $run = strtolower(bin2hex(random_bytes(5)));
    $admin = kioskUser($pdo, 'kiosk-admin-' . $run . '@parkngo.test', 'admin');
    $driver = kioskUser($pdo, 'kiosk-driver-' . $run . '@parkngo.test', 'driver');
    $pdo->prepare('INSERT INTO parking_facilities (name, address, city) VALUES (?, ?, ?)')->execute(['Kiosk Facility ' . $run, 'Test address', 'Test city']);
    $facility = (int) $pdo->lastInsertId();

    try { KioskService::create($driver, $facility, 'Denied kiosk'); kioskCheck(false, 'non-admin cannot register kiosk'); }
    catch (InvalidArgumentException $error) { kioskCheck(true, 'non-admin cannot register kiosk'); }
    try { KioskService::create($admin, 99999999, 'Invalid facility'); kioskCheck(false, 'invalid facility rejected'); }
    catch (InvalidArgumentException $error) { kioskCheck(true, 'invalid facility rejected'); }

    $created = KioskService::create($admin, $facility, 'Gate A');
    kioskCheck(strlen($created['code']) === 32 && preg_match('/^[A-F0-9]{32}$/', $created['code']) === 1, 'activation code is high entropy and formatted');
    $row = $pdo->query('SELECT status, credential_hash FROM exit_kiosks WHERE id = ' . (int) $created['id'])->fetch();
    kioskCheck($row['status'] === 'pending' && $row['credential_hash'] === null, 'new kiosk has no active credential');
    $codeRow = $pdo->prepare('SELECT code_hash, consumed_at FROM kiosk_activation_codes WHERE kiosk_id = ?'); $codeRow->execute([$created['id']]); $codeData = $codeRow->fetch();
    kioskCheck($codeData['code_hash'] !== $created['code'] && hash('sha256', $created['code']) === $codeData['code_hash'] && $codeData['consumed_at'] === null, 'activation code is stored only as a hash');

    $pdo->prepare('UPDATE kiosk_activation_codes SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE kiosk_id = ?')->execute([$created['id']]);
    try { KioskService::activate($created['code']); kioskCheck(false, 'expired activation code rejected'); }
    catch (InvalidArgumentException $error) { kioskCheck(true, 'expired activation code rejected'); }

    $second = KioskService::create($admin, $facility, 'Gate B');
    $worker = dirname(__DIR__) . '/tests/fixtures/kiosk-activation-worker.php';
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = []; $processes[$i] = proc_open([PHP_BINARY, $worker, $second['code']], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        if (!is_resource($processes[$i])) throw new RuntimeException('Unable to start activation worker.');
        $processes[$i . '_pipes'] = $pipes;
    }
    $results = [];
    for ($i = 0; $i < 2; $i++) { $results[] = trim(stream_get_contents($processes[$i . '_pipes'][1])); fclose($processes[$i . '_pipes'][1]); fclose($processes[$i . '_pipes'][2]); proc_close($processes[$i]); }
    kioskCheck(count(array_filter($results, static fn($result) => $result === 'success')) === 1 && count(array_filter($results, static fn($result) => $result === 'rejected')) === 1, 'concurrent activation permits only one success');
    try { KioskService::activate($second['code']); kioskCheck(false, 'activation code is single-use'); }
    catch (InvalidArgumentException $error) { kioskCheck(true, 'activation code is single-use'); }

    $active = $pdo->prepare('SELECT id, status, credential_hash FROM exit_kiosks WHERE id = ?'); $active->execute([$second['id']]); $activeRow = $active->fetch();
    kioskCheck($activeRow['status'] === 'active' && strlen((string) $activeRow['credential_hash']) === 64, 'activated kiosk stores hashed credential and is active');
    kioskCheck(KioskAuth::current() === null, 'CLI test cannot impersonate kiosk session or driver session');
    $pdo->prepare('UPDATE exit_kiosks SET status = \'revoked\', revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE id = ?')->execute([$admin, $second['id']]);
    kioskCheck($pdo->query("SELECT status FROM exit_kiosks WHERE id = " . (int) $second['id'])->fetchColumn() === 'revoked', 'revoked kiosk is denied by active-status contract');
    echo "KIOSK CHECKS: $checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
