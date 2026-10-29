<?php
declare(strict_types=1);

// Real MySQL + HTTP checks. Run against a separate *_test database and matching test server.
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\{Auth, Database, Environment, RateLimiter};

if (PHP_SAPI !== 'cli' || Environment::get('APP_ENV', 'local') !== 'local'
    || !str_ends_with(Environment::get('DB_DATABASE'), '_test')) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated DB_DATABASE ending in _test. See docs/PHASE_2.md.\n"); exit(1);
}
$base = rtrim(Environment::get('TEST_BASE_URL', 'http://127.0.0.1:8088'), '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('HTTP tests are restricted to loopback.');
$count = 0;
function check(bool $condition, string $label): void
{
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++; echo "PASS $label\n";
}

final class Browser
{
    private \CurlHandle $curl;
    public array $headers = [];
    public int $status = 0;
    public string $body = '';
    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    }
    public function request(string $page, ?array $data = null): self
    {
        $this->headers = [];
        curl_setopt($this->curl, CURLOPT_URL, $this->base . '/index.php?' . $page);
        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, function ($curl, string $line): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $this->headers[strtolower($key)] = trim($value); }
            return strlen($line);
        });
        if ($data !== null) {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($data));
        } else curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        $result = curl_exec($this->curl);
        if ($result === false) throw new RuntimeException('HTTP failed: ' . curl_error($this->curl));
        $this->body = $result;
        $this->status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        return $this;
    }
    public function token(string $page): string
    {
        $this->request('page=' . $page);
        if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->body, $match)) throw new RuntimeException('Missing CSRF token: ' . $page);
        return $match[1];
    }
    public function cookie(): string
    {
        foreach (curl_getinfo($this->curl, CURLINFO_COOKIELIST) as $cookie) {
            $parts = explode("\t", $cookie);
            if (($parts[5] ?? '') === 'parkngo_session') return $parts[6];
        }
        throw new RuntimeException('No session cookie.');
    }
    public function login(string $email, string $password): void
    {
        $csrf = $this->token('login');
        $this->request('page=login', ['csrf' => $csrf, 'email' => $email, 'password' => $password]);
    }
    public function logout(): void
    {
        $csrf = $this->token('profile');
        $this->request('page=logout', ['csrf' => $csrf]);
    }
}

try {
    $preflight = new Browser($base);
    $preflight->request('page=home');
    $expectedDatabase = hash('sha256', Environment::get('DB_HOST', '127.0.0.1') . ':' . Environment::get('DB_PORT', '3306') . ':' . Environment::get('DB_DATABASE'));
    check(hash_equals($expectedDatabase, $preflight->headers['x-parkngo-test-database'] ?? ''), 'HTTP server and CLI use the same isolated test database');
    $pdo = Database::connection();
    // Preserve all existing records, including throttle buckets. Use a fresh isolated
    // database per run so rate limits from prior executions cannot alter results.
    $run = bin2hex(random_bytes(5));
    $password = 'Test-Only-' . bin2hex(random_bytes(8));
    $newPassword = 'New-Test-' . bin2hex(random_bytes(8));
    $email = 'driver-' . $run . '@parkngo.test';
    check((int) $pdo->query('SELECT COUNT(*) FROM parking_facilities')->fetchColumn() >= 3, 'development facilities seeded');
    check((int) $pdo->query('SELECT COUNT(*) FROM parking_slots')->fetchColumn() >= 60, 'development floors and slots seeded');
    check((int) $pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn() === 0, 'no fake successful payments');
    $guest = new Browser($base);
    $guest->request('page=dashboard');
    check($guest->status === 303 && str_contains($guest->headers['location'] ?? '', 'page=login'), 'guest protected-route redirect');
    check(str_contains($guest->headers['set-cookie'] ?? '', 'HttpOnly') && str_contains($guest->headers['set-cookie'] ?? '', 'SameSite=Lax'), 'session cookie security attributes');
    $guest->request('page=register', ['email' => $email, 'password' => $password]);
    check($guest->status === 419, 'missing CSRF rejected');
    $csrf = $guest->token('register');
    $guest->request('page=register', ['csrf' => $csrf, 'name' => 'A', 'email' => 'invalid', 'password' => 'short', 'password_confirmation' => 'different']);
    check($guest->status === 200 && str_contains($guest->body, 'Enter a valid email'), 'registration input validation');
    $oldCookie = $guest->cookie();
    $guest->request('page=register', ['csrf' => $csrf, 'name' => 'Test Driver', 'email' => strtoupper($email), 'password' => $password, 'password_confirmation' => $password, 'role' => 'admin']);
    check($guest->status === 303 && str_contains($guest->headers['location'] ?? '', 'page=dashboard'), 'registration succeeds');
    check($oldCookie !== $guest->cookie(), 'session ID rotates after registration');
    $find = $pdo->prepare('SELECT * FROM users WHERE email = ?'); $find->execute([$email]); $driver = $find->fetch();
    check($driver && $driver['role'] === 'driver', 'submitted admin role cannot escalate registration');
    check(password_verify($password, $driver['password_hash']) && $driver['password_hash'] !== $password, 'password stored as verifiable hash');
    check(Auth::passwordError(str_repeat('a', 11)) !== null && Auth::passwordError(str_repeat('a', 12)) === null
        && Auth::passwordError(str_repeat('a', 72)) === null && Auth::passwordError(str_repeat('a', 73)) !== null
        && Auth::passwordError("long-password\0") !== null, 'password byte limits and null bytes validated');
    $guest->request('page=dashboard'); check($guest->status === 200 && str_contains($guest->body, 'driver-bottom-nav'), 'driver dashboard and mobile navigation');
    $guest->request('page=admin-dashboard'); check($guest->status === 403, 'driver denied administrator workspace');
    $guest->request('page=attendant-dashboard'); check($guest->status === 403, 'driver denied attendant workspace');
    $csrf = $guest->token('profile');
    $guest->request('page=profile', ['csrf' => $csrf, 'name' => '<script>Driver</script>', 'phone' => '+63 917 123 4567', 'role' => 'admin', 'email' => 'changed@parkngo.test']);
    check($guest->status === 303, 'profile update saves');
    $guest->request('page=profile'); check(str_contains($guest->body, '&lt;script&gt;Driver&lt;/script&gt;') && !str_contains($guest->body, '<script>Driver</script>'), 'stored profile output escaped');
    $find->execute([$email]); $saved = $find->fetch();
    check($saved['role'] === 'driver' && $saved['email'] === $email, 'profile cannot change email or role');
    $guest->request('page=logout'); check($guest->status === 405, 'GET cannot log out');
    $guest->logout(); check($guest->status === 303, 'POST logout succeeds');
    $guest->request('page=dashboard'); check($guest->status === 303, 'logout removes protected access');
    $dupe = new Browser($base); $csrf = $dupe->token('register');
    $dupe->request('page=register', ['csrf' => $csrf, 'name' => 'Duplicate', 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
    check($dupe->status === 200 && str_contains($dupe->body, 'Unable to create'), 'duplicate email handled without server error');
    $guest->login($email, 'wrong-password'); check($guest->status === 200 && str_contains($guest->body, 'email or password is incorrect'), 'incorrect password rejected');
    $guest->login("' OR 1=1 --@parkngo.test", $password); check($guest->status === 200 && str_contains($guest->body, 'email or password is incorrect'), 'SQL injection login rejected');
    $guest->login($email, $password); check($guest->status === 303, 'correct password accepted');
    $session = $guest->cookie();
    $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([$driver['id']]);
    $guest->request('page=dashboard'); check($guest->status === 303, 'disabled account immediately loses session access');
    $guest->login($email, $password); check($guest->status === 200, 'disabled account cannot log in');
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$driver['id']]);
    $guest->login($email, $password);
    $sessionPath = BASE_PATH . '/storage/sessions/sess_' . $guest->cookie();
    $sessionData = file_get_contents($sessionPath);
    file_put_contents($sessionPath, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 1900) . ';', $sessionData));
    $guest->request('page=dashboard'); check($guest->status === 303, 'idle session expires after 30 minutes');
    $guest->login($email, $password);
    $sessionPath = BASE_PATH . '/storage/sessions/sess_' . $guest->cookie();
    file_put_contents($sessionPath, preg_replace('/authenticated_at\|i:\d+;/', 'authenticated_at|i:' . (time() - 28900) . ';', file_get_contents($sessionPath)));
    $guest->request('page=dashboard'); check($guest->status === 303, 'absolute session expires after eight hours');
    foreach (['admin' => 'admin-dashboard', 'attendant' => 'attendant-dashboard'] as $role => $dashboard) {
        $privEmail = $role . '-' . $run . '@parkngo.test';
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')->execute(['Test ' . $role, $privEmail, Auth::hashPassword($password), $role]);
        $privileged = new Browser($base); $privileged->login($privEmail, $password);
        check($privileged->status === 303 && str_contains($privileged->headers['location'] ?? '', $dashboard), "$role login routes to own dashboard");
        $privileged->request('page=' . $dashboard); check($privileged->status === 200, "$role workspace accessible");
        $privileged->request('page=' . ($role === 'admin' ? 'attendant-dashboard' : 'admin-dashboard')); check($privileged->status === 403, "$role denied other staff role");
    }
    $active = new Browser($base); $active->login($email, $password);
    $recover = new Browser($base); $csrf = $recover->token('forgot-password');
    $recover->request('page=forgot-password', ['csrf' => $csrf, 'email' => $email]);
    check($recover->status === 303, 'password recovery accepts known email');
    $recover->request('page=forgot-password'); $knownBody = $recover->body;
    $csrf = $recover->token('forgot-password');
    $recover->request('page=forgot-password', ['csrf' => $csrf, 'email' => 'unknown-' . $run . '@parkngo.test']);
    $recover->request('page=forgot-password');
    check(str_contains($knownBody, 'If an active account matches') && str_contains($recover->body, 'If an active account matches'), 'recovery gives generic known and unknown account response');
    $token = '';
    foreach (glob(BASE_PATH . '/storage/mail/*.eml') as $file) {
        $mail = file_get_contents($file);
        if (str_contains($mail, 'To: ' . $email . "\n") && preg_match('/token=([a-f0-9]{64})/', $mail, $match)) $token = $match[1];
    }
    check($token !== '', 'local recovery link written to private outbox');
    $query = $pdo->prepare('SELECT token_hash FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL'); $query->execute([$driver['id']]);
    check($query->fetchColumn() === hash('sha256', $token), 'only recovery token hash stored in database');
    check(!str_contains($knownBody, $token), 'recovery token never returned in public response');
    $recover->request('page=reset-password&token=' . $token);
    check($recover->status === 303 && !str_contains($recover->headers['location'] ?? '', 'token='), 'reset link captured then removed from URL');
    $csrf = $recover->token('reset-password');
    $recover->request('page=reset-password', ['csrf' => $csrf, 'password' => $newPassword, 'password_confirmation' => $newPassword]);
    check($recover->status === 303, 'valid single-use reset updates password');
    $active->request('page=dashboard'); check($active->status === 303, 'password reset invalidates other sessions');
    $recover->request('page=reset-password&token=' . $token); $csrf = $recover->token('reset-password');
    $recover->request('page=reset-password', ['csrf' => $csrf, 'password' => $password, 'password_confirmation' => $password]);
    check($recover->status === 200 && str_contains($recover->body, 'invalid, expired, or already used'), 'reset token replay rejected');
    $active->login($email, $password); check($active->status === 200, 'old password rejected after reset');
    $active->login($email, $newPassword); check($active->status === 303, 'new password accepted');
    $active->logout();
    $csrf = $recover->token('forgot-password'); $recover->request('page=forgot-password', ['csrf' => $csrf, 'email' => $email]);
    $pdo->prepare('UPDATE password_reset_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE user_id = ? AND used_at IS NULL')->execute([$driver['id']]);
    $expectedToken = $pdo->prepare('SELECT token_hash FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $expectedToken->execute([$driver['id']]);
    $expectedHash = $expectedToken->fetchColumn();
    $files = glob(BASE_PATH . '/storage/mail/*.eml');
    foreach ($files as $file) {
        $mail = file_get_contents($file);
        if (str_contains($mail, 'To: ' . $email . "\n") && preg_match('/token=([a-f0-9]{64})/', $mail, $match)
            && hash_equals($expectedHash, hash('sha256', $match[1]))) { $expiredToken = $match[1]; break; }
    }
    $recover->request('page=reset-password&token=' . $expiredToken); $csrf = $recover->token('reset-password');
    $recover->request('page=reset-password', ['csrf' => $csrf, 'password' => $password, 'password_confirmation' => $password]);
    check($recover->status === 200 && str_contains($recover->body, 'invalid, expired, or already used'), 'expired reset token rejected');
    $limited = new Browser($base);
    for ($i = 0; $i < 11; $i++) $limited->login('rate-' . $run . '@parkngo.test', 'bad-password');
    check($limited->status === 429 && isset($limited->headers['retry-after']), 'login throttling enforced through HTTP');
    check(RateLimiter::consume('test-window', $run, 1, 900) && !RateLimiter::consume('test-window', $run, 1, 900), 'database rate limit stops at maximum');
    $key = hash('sha256', 'test-window:' . $run); $pdo->prepare('UPDATE rate_limits SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE bucket_key = ?')->execute([$key]);
    check(RateLimiter::consume('test-window', $run, 1, 900), 'expired throttle window resets');
    $audit = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE actor_id = ?'); $audit->execute([$driver['id']]);
    check((int) $audit->fetchColumn() >= 5, 'account audit events persisted');
    $guest->request('page%5B%5D=home'); check($guest->status === 404, 'malformed route handled safely');
    $guest->request('page=home'); check(isset($guest->headers['content-security-policy']) && ($guest->headers['cache-control'] ?? '') === 'no-store', 'CSP and private-response cache headers');
    echo "\n$count checks passed. Test records retained only in isolated test database.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
