<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\{Database, KioskService};

if (getenv('APP_ENV') !== 'local' || !str_ends_with(getenv('DB_DATABASE') ?: '', '_test')) { fwrite(STDERR, "Use an isolated *_test database.\n"); exit(1); }
$pdo = Database::connection(); $run = strtolower(bin2hex(random_bytes(5))); $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$hashPassword = password_hash('fixture-password', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\'admin\')')->execute(['Exit Admin ' . $run, 'exit-admin-' . $run . '@parkngo.test', $hashPassword]); $admin = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,\'driver\')')->execute(['Exit Driver ' . $run, 'exit-driver-' . $run . '@parkngo.test', $hashPassword]); $driver = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_facilities (name,address,city) VALUES (?,?,?)')->execute(['Exit Facility A ' . $run, 'A address', 'A city']); $facilityA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_facilities (name,address,city) VALUES (?,?,?)')->execute(['Exit Facility B ' . $run, 'B address', 'B city']); $facilityB = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_floors (facility_id,label) VALUES (?,?)')->execute([$facilityA, 'Ground']); $floorA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_floors (facility_id,label) VALUES (?,?)')->execute([$facilityB, 'Ground']); $floorB = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$floorA, 'A-1']); $slotA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$floorB, 'B-1']); $slotB = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_rates (facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$facilityA, 5000, 2000, 2500]); $rateA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_rates (facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$facilityB, 5000, 2000, 2500]); $rateB = (int) $pdo->lastInsertId();

function exitFixture(PDO $pdo, string $run, int $driver, int $facility, int $slot, int $rate, string $label, bool $paid, string $sessionState = 'none'): array
{
    $reference = 'PNG-' . strtoupper($run . '-' . $label); $start = new DateTimeImmutable('now', new DateTimeZone('UTC')); $end = $start->modify('+60 minutes');
    $snapshot = json_encode(['version' => 1, 'rate' => ['initial_centavos' => 5000]], JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,status,estimated_centavos,rate_snapshot) VALUES (?,?,?,?,?,?,\'confirmed\',?,?)')->execute([$reference, $driver, $slot, $rate, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 5000, $snapshot]); $reservation = (int) $pdo->lastInsertId();
    if ($paid) $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_request_reference,local_status,verified_at) VALUES (?,?,?,?,?,'PHP',?,'verified',UTC_TIMESTAMP())")->execute(['PAY-' . strtoupper($run . '-' . $label), $driver, $reservation, 'INITIAL_RESERVATION', 5000, 'MAYA-' . strtoupper($run . '-' . $label)]);
    $manual = 'MANUAL-' . strtoupper($run . '-' . $label); $token = 'PNGO-' . strtoupper(bin2hex(random_bytes(16)));
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 hours')->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO qr_tickets (reservation_id,token_hash,manual_code_hash,status,expires_at,last_issued_at) VALUES (?,?,?,\'active\',?,UTC_TIMESTAMP())')->execute([$reservation, hash('sha256', $token), hash('sha256', $manual), $expires]);
    $ticket = (int) $pdo->lastInsertId();
    if ($sessionState !== 'none') {
        $paidUntil = $sessionState === 'overdue' ? 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)' : 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)';
        $exitAt = $sessionState === 'exited' ? 'UTC_TIMESTAMP()' : 'NULL'; $status = $sessionState === 'exited' ? 'completed' : 'active';
        $pdo->exec("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,purchased_minutes,paid_until,exit_at,status) VALUES ($reservation,$driver,UTC_TIMESTAMP(),60,$paidUntil,$exitAt,'$status')");
    }
    return ['reservation' => $reservation, 'ticket' => $ticket, 'manual' => $manual];
}

function exitRequest(string $url, string $cookie, string $method = 'GET', array $fields = []): array
{
    $handle = curl_init($url); curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $fields, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5]); $body = curl_exec($handle); if ($body === false) throw new RuntimeException(curl_error($handle)); $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle); return [$status, (string) $body];
}
function exitCsrf(string $body): string { if (!preg_match('/name="csrf" value="([^"]+)"/', $body, $match)) throw new RuntimeException('Missing kiosk CSRF token.'); return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'); }
function exitCheck(bool $condition, string $label): void { if (!$condition) throw new RuntimeException('FAIL ' . $label); echo "PASS $label\n"; }

$wrongFacility = exitFixture($pdo, $run, $driver, $facilityB, $slotB, $rateB, 'WRONG', true);
$unpaid = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'UNPAID', false);
$noEntry = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'NOENTRY', true);
$alreadyExited = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'EXITED', true, 'exited');
$overdue = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'OVERDUE', true, 'overdue');
$crossing = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'CROSSING', true, 'active');
$concurrent = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'CONCURRENT', true, 'active');
$valid = exitFixture($pdo, $run, $driver, $facilityA, $slotA, $rateA, 'VALID', true, 'active');
$kiosk = KioskService::create($admin, $facilityA, 'Exit Test Kiosk'); $cookie = tempnam(sys_get_temp_dir(), 'parkngo-exit-'); $base = 'http://127.0.0.1:8091/index.php';
try {
    [, $body] = exitRequest($base . '?page=kiosk-activate', $cookie); $csrf = exitCsrf($body); [$activationStatus] = exitRequest($base . '?page=kiosk-activate', $cookie, 'POST', ['csrf' => $csrf, 'code' => $kiosk['code']]); if ($activationStatus !== 303) throw new RuntimeException('Kiosk fixture activation failed.'); [, $body] = exitRequest($base . '?page=kiosk-home', $cookie); $csrf = exitCsrf($body);
    foreach ([$wrongFacility['manual'] => 'wrong-facility', $unpaid['manual'] => 'unpaid', $noEntry['manual'] => 'no-entry', $alreadyExited['manual'] => 'already-exited', $overdue['manual'] => 'overdue'] as $code => $label) { [$status] = exitRequest($base . '?page=kiosk-verify', $cookie, 'POST', ['csrf' => $csrf, 'code' => $code]); exitCheck($status === 422, $label . ' ticket is denied'); [, $body] = exitRequest($base . '?page=kiosk-home', $cookie); $csrf = exitCsrf($body); }
    [$status, $body] = exitRequest($base . '?page=kiosk-verify', $cookie, 'POST', ['csrf' => $csrf, 'code' => $valid['manual']]); exitCheck($status === 200 && str_contains($body, 'Confirm Exit'), 'valid manual ticket reaches separate confirmation'); $confirmCsrf = exitCsrf($body); [$status, $body] = exitRequest($base . '?page=kiosk-exit', $cookie, 'POST', ['csrf' => $confirmCsrf]); exitCheck($status === 200 && str_contains($body, 'Exit confirmed'), 'active session within paid duration exits successfully');
    $check = $pdo->prepare('SELECT status,exit_at FROM parking_sessions WHERE id=(SELECT id FROM parking_sessions WHERE reservation_id=?)'); $check->execute([$valid['reservation']]); $validRow = $check->fetch(); exitCheck($validRow['status'] === 'completed' && $validRow['exit_at'] !== null, 'exit finalizes the authoritative parking session exactly once'); [$status] = exitRequest($base . '?page=kiosk-verify', $cookie, 'POST', ['csrf' => $confirmCsrf, 'code' => $valid['manual']]); exitCheck($status === 422, 'ticket replay after exit is rejected');
    [, $body] = exitRequest($base . '?page=kiosk-home', $cookie); $csrf = exitCsrf($body); [, $body] = exitRequest($base . '?page=kiosk-verify', $cookie, 'POST', ['csrf' => $csrf, 'code' => $crossing['manual']]); $confirmCsrf = exitCsrf($body); $pdo->prepare('UPDATE parking_sessions SET paid_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE reservation_id=?')->execute([$crossing['reservation']]); [$status] = exitRequest($base . '?page=kiosk-exit', $cookie, 'POST', ['csrf' => $confirmCsrf]); exitCheck($status === 422, 'overtime appearing between preview and confirmation blocks exit');
    [, $body] = exitRequest($base . '?page=kiosk-home', $cookie); $csrf = exitCsrf($body); [, $body] = exitRequest($base . '?page=kiosk-verify', $cookie, 'POST', ['csrf' => $csrf, 'code' => $concurrent['manual']]); $confirmCsrf = exitCsrf($body); $handles = []; $multi = curl_multi_init(); for ($i = 0; $i < 2; $i++) { $handle = curl_init($base . '?page=kiosk-exit'); curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['csrf' => $confirmCsrf], CURLOPT_TIMEOUT => 5]); curl_multi_add_handle($multi, $handle); $handles[] = $handle; } do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1); } while ($running); $codes = []; foreach ($handles as $handle) { $codes[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($multi, $handle); curl_close($handle); } curl_multi_close($multi); exitCheck(count(array_filter($codes, static fn(int $code): bool => $code === 200)) === 1 && count(array_filter($codes, static fn(int $code): bool => $code === 422)) === 1, 'concurrent confirmations allow only one success');
    echo "EXIT HTTP CHECKS PASSED\n";
} finally { if (is_string($cookie) && is_file($cookie)) unlink($cookie); }
