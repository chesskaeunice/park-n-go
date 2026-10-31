<?php
declare(strict_types=1);

// Shared fixtures for the Phase 6 completion suites. Only usable against an isolated *_test database.
use ParkNGo\Services\{Auth, Database, Environment};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('BASE_PATH')) require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (Environment::get('APP_ENV', 'local') !== 'local' || !str_ends_with(Environment::get('DB_DATABASE'), '_test')) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated *_test database.\n"); exit(1);
}

$GLOBALS['p6_checks'] = 0;
function p6_check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException('FAIL ' . $label); ++$GLOBALS['p6_checks']; echo "PASS $label\n"; }
function p6_throws(callable $callback, string $label, string $contains = '', string $class = Throwable::class): void
{
    try { $callback(); } catch (Throwable $error) {
        if (!$error instanceof $class) throw new RuntimeException('FAIL ' . $label . ' (unexpected ' . get_class($error) . ': ' . $error->getMessage() . ')');
        p6_check($contains === '' || str_contains($error->getMessage(), $contains), $label . ($contains === '' ? '' : ' [' . $error->getMessage() . ']'));
        return;
    }
    throw new RuntimeException('FAIL ' . $label . ' (no exception)');
}
function p6_run(): string { static $run = null; return $run ??= strtolower(bin2hex(random_bytes(5))); }
function p6_uid(): string { return p6_run() . '-' . bin2hex(random_bytes(4)); }
function p6_utc(string $modify = 'now'): string { return (new DateTimeImmutable('@' . time()))->modify($modify)->format('Y-m-d H:i:s'); }
function p6_db_now(): string { return (string) Database::connection()->query('SELECT UTC_TIMESTAMP()')->fetchColumn(); }

function p6_user(string $role, string $password = 'Phase6-Password-123'): array
{
    $email = 'p6-' . $role . '-' . p6_uid() . '@parkngo.test';
    Database::connection()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)')->execute(['Phase 6 ' . ucfirst($role), $email, Auth::hashPassword($password), $role]);
    return ['id' => (int) Database::connection()->lastInsertId(), 'email' => $email, 'password' => $password];
}

/** Facility with one floor, one slot, and a rate: hourly 20.00 and overtime 25.00 unless overridden. */
function p6_facility(int $hourCentavos = 2000, ?int $overtimeCentavos = 2500): array
{
    $pdo = Database::connection();
    $pdo->prepare('INSERT INTO parking_facilities (name,address,city) VALUES (?,?,?)')->execute(['P6 Facility ' . p6_uid(), 'Test address', 'Test City']); $facility = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_floors (facility_id,label) VALUES (?,?)')->execute([$facility, 'Ground']); $floor = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$floor, 'S-1']); $slot = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parking_rates (facility_id,initial_minutes,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES (?,?,?,?,?,?)')->execute([$facility, 60, 5000, $hourCentavos, $overtimeCentavos, p6_utc('-2 days')]); $rate = (int) $pdo->lastInsertId();
    return ['facility' => $facility, 'floor' => $floor, 'slot' => $slot, 'rate' => $rate];
}

function p6_reservation(int $user, array $place, string $start, string $end, string $status = 'confirmed'): int
{
    Database::connection()->prepare('INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,status,estimated_centavos,rate_snapshot) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute(['PNG-' . strtoupper(bin2hex(random_bytes(10))), $user, $place['slot'], $place['rate'], $start, $end, $status, 5000, json_encode(['version' => 1, 'cancellation_cutoff_minutes' => 60], JSON_THROW_ON_ERROR)]);
    return (int) Database::connection()->lastInsertId();
}

function p6_hold(int $user, array $place, int $reservation, string $start, string $end, string $expires): void
{
    Database::connection()->prepare('INSERT INTO reservation_holds (user_id,slot_id,reservation_id,token_hash,starts_at,ends_at,expires_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$user, $place['slot'], $reservation, hash('sha256', random_bytes(16)), $start, $end, $expires]);
}

/**
 * A verified, entered parking session. $paidUntil is relative to now (e.g. '+30 minutes', '-5 minutes').
 * Returns ids plus the ticket token and manual code so tests can use the same QR ticket throughout.
 */
function p6_session(array $place, ?array $driver = null, string $paidUntil = '+30 minutes', string $entry = '-30 minutes'): array
{
    $pdo = Database::connection(); $driver ??= p6_user('driver');
    // One vehicle per slot: each session gets its own slot on the facility floor.
    $pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$place['floor'], 'S-' . p6_uid()]); $place['slot'] = (int) $pdo->lastInsertId();
    $start = p6_utc($entry); $end = p6_utc($paidUntil);
    if ($end <= $start) $end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+1 minute')->format('Y-m-d H:i:s');
    $reservation = p6_reservation($driver['id'], $place, $start, $end);
    $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_request_reference,local_status,verified_at) VALUES (?,?,?,'INITIAL_RESERVATION',5000,'PHP',?,'verified',UTC_TIMESTAMP())")
        ->execute([$ref = 'PNGO-' . strtoupper(bin2hex(random_bytes(12))), $driver['id'], $reservation, $ref]);
    $token = 'PNGO-' . strtoupper(bin2hex(random_bytes(32))); $manual = strtoupper(bin2hex(random_bytes(6)));
    $pdo->prepare("INSERT INTO qr_tickets (reservation_id,token_hash,manual_code_hash,status,expires_at,last_issued_at) VALUES (?,?,?,'active',?,UTC_TIMESTAMP())")
        ->execute([$reservation, hash('sha256', $token), hash('sha256', $manual), (new DateTimeImmutable($end, new DateTimeZone('UTC')))->modify('+30 minutes')->format('Y-m-d H:i:s')]);
    $ticket = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,purchased_minutes,paid_until,status) VALUES (?,?,?,?,?,'active')")
        ->execute([$reservation, $driver['id'], $start, 60, p6_utc($paidUntil)]);
    return ['session' => (int) $pdo->lastInsertId(), 'reservation' => $reservation, 'ticket' => $ticket, 'token' => $token, 'manual' => $manual, 'driver' => $driver, 'place' => $place];
}

function p6_row(string $sql, array $params = []): array|false { $find = Database::connection()->prepare($sql); $find->execute($params); return $find->fetch(); }
function p6_value(string $sql, array $params = []): mixed { $find = Database::connection()->prepare($sql); $find->execute($params); return $find->fetchColumn(); }
function p6_session_row(int $id): array { return p6_row('SELECT * FROM parking_sessions WHERE id=?', [$id]); }
function p6_attempt_by_url(string $url): array
{
    $row = p6_row('SELECT * FROM payment_attempts WHERE maya_redirect_url=?', [$url]);
    if (!$row) throw new RuntimeException('No payment attempt for redirect URL.');
    return $row;
}
function p6_latest_attempt(int $session): array|false { return p6_row('SELECT * FROM payment_attempts WHERE parking_session_id=? ORDER BY id DESC LIMIT 1', [$session]); }
function p6_ledger(array $attempt): array|false
{
    $table = $attempt['payment_type'] === 'PARKING_EXTENSION' ? 'parking_extensions' : 'parking_overtime_settlements';
    return p6_row("SELECT * FROM {$table} WHERE payment_attempt_id=?", [$attempt['id']]);
}
function p6_audits(string $action): int { return (int) p6_value('SELECT COUNT(*) FROM audit_logs WHERE action=?', [$action]); }

// Fake Maya control. The provider never reports success unless a test sets it.
function p6_fake_dir(): string { $dir = getenv('FAKE_MAYA_STATE') ?: ''; if ($dir === '' || !is_dir($dir)) throw new RuntimeException('FAKE_MAYA_STATE is not configured.'); return $dir; }
function p6_fake_mode(array $mode): void { file_put_contents(p6_fake_dir() . '/mode.json', json_encode($mode)); }
function p6_fake_count(): int { $file = p6_fake_dir() . '/checkout-count'; return is_file($file) ? (int) file_get_contents($file) : 0; }
function p6_fake_status(string $checkoutId, string $status): void
{
    $file = p6_fake_dir() . '/checkouts/' . $checkoutId . '.json';
    if (!preg_match('/^fake-[a-f0-9]{24}$/D', $checkoutId) || !is_file($file)) throw new RuntimeException('Unknown fake checkout.');
    $data = json_decode((string) file_get_contents($file), true); $data['status'] = $status; file_put_contents($file, json_encode($data));
}
function p6_fake_checkout(string $checkoutId): array { return json_decode((string) file_get_contents(p6_fake_dir() . '/checkouts/' . $checkoutId . '.json'), true); }
function p6_maya_enabled(bool $enabled): void
{
    putenv('MAYA_ENABLED=' . ($enabled ? 'true' : 'false'));
    if ($enabled) { putenv('MAYA_PUBLIC_KEY=' . getenv('FAKE_MAYA_PUBLIC_KEY')); putenv('MAYA_SECRET_KEY=' . getenv('FAKE_MAYA_SECRET_KEY')); }
}
function p6_webhook_payload(array $attempt, string $status = 'PAYMENT_SUCCESS', ?string $id = null): array
{
    return ['id' => $id ?? (string) $attempt['maya_checkout_id'], 'requestReferenceNumber' => $attempt['reference'], 'paymentStatus' => $status, 'totalAmount' => ['value' => number_format((int) $attempt['amount_centavos'] / 100, 2, '.', ''), 'currency' => 'PHP']];
}

// HTTP helpers for the PHP development server started by tests/run-phase6.php.
function p6_base(): string { return rtrim(getenv('PARKNGO_TEST_BASE_URL') ?: 'http://127.0.0.1:8091', '/') . '/index.php'; }
function p6_cookie(): string { $file = tempnam(sys_get_temp_dir(), 'p6c'); $GLOBALS['p6_cookies'][] = $file; return $file; }
function p6_http(string $query, string $cookie, string $method = 'GET', array $fields = []): array
{
    $handle = curl_init(p6_base() . '?' . $query);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    $raw = curl_exec($handle); if ($raw === false) throw new RuntimeException('HTTP failure: ' . curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE); curl_close($handle);
    $headers = substr((string) $raw, 0, $size); $location = preg_match('/^Location:\s*(\S+)/mi', $headers, $match) ? $match[1] : null;
    return ['status' => $status, 'body' => substr((string) $raw, $size), 'location' => $location, 'headers' => $headers];
}
function p6_csrf(string $body): string { if (!preg_match('/name="csrf" value="([^"]+)"/', $body, $match)) throw new RuntimeException('Missing CSRF token.'); return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'); }
function p6_login(array $user): string
{
    $cookie = p6_cookie(); $page = p6_http('page=login', $cookie);
    $result = p6_http('page=login', $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'email' => $user['email'], 'password' => $user['password']]);
    if ($result['status'] !== 303) throw new RuntimeException('Login failed for fixture user.');
    return $cookie;
}
function p6_kiosk(int $facility, ?int $admin = null): string
{
    $admin ??= p6_user('admin')['id'];
    $created = \ParkNGo\Services\KioskService::create($admin, $facility, 'P6 Gate ' . p6_uid());
    $cookie = p6_cookie(); $page = p6_http('page=kiosk-activate', $cookie);
    $result = p6_http('page=kiosk-activate', $cookie, 'POST', ['csrf' => p6_csrf($page['body']), 'code' => $created['code']]);
    if ($result['status'] !== 303) throw new RuntimeException('Kiosk activation failed (' . $result['status'] . ').');
    return $cookie;
}
/** Kiosk verify + confirm through the real HTTP endpoints. Returns [verifyStatus, confirmStatus|null, body]. */
function p6_kiosk_exit(string $cookie, string $code): array
{
    $home = p6_http('page=kiosk-home', $cookie);
    $verify = p6_http('page=kiosk-verify', $cookie, 'POST', ['csrf' => p6_csrf($home['body']), 'code' => $code]);
    if ($verify['status'] !== 200 || !str_contains($verify['body'], 'Confirm Exit')) return [$verify['status'], null, $verify['body']];
    $confirm = p6_http('page=kiosk-exit', $cookie, 'POST', ['csrf' => p6_csrf($verify['body'])]);
    return [$verify['status'], $confirm['status'], $confirm['body']];
}
function p6_errors(string $body): string { return preg_match('/class="form-errors"[^>]*>(.*?)<\/div>/s', $body, $match) ? trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES)) : ''; }
function p6_clock(?string $utc): void
{
    $file = getenv('PARKNGO_TEST_CLOCK_FILE') ?: ''; if ($file === '') throw new RuntimeException('PARKNGO_TEST_CLOCK_FILE is not configured.');
    file_put_contents($file, $utc ?? '');
}
register_shutdown_function(static function (): void {
    foreach ($GLOBALS['p6_cookies'] ?? [] as $file) if (is_file($file)) @unlink($file);
    if (getenv('PARKNGO_TEST_CLOCK_FILE')) @file_put_contents((string) getenv('PARKNGO_TEST_CLOCK_FILE'), '');
});
