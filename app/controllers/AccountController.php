<?php
declare(strict_types=1);
namespace ParkNGo\Controllers;
use ParkNGo\Services\{Auth, Audit, Database, PasswordReset, RateLimiter};

final class AccountController
{
    public array $errors = [];
    public array $old = [];

    public function handle(string $page): void
    {
        if ($page === 'reset-password' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
            $token = is_string($_GET['token']) ? $_GET['token'] : '';
            $_SESSION['reset_token'] = preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '';
            redirect('reset-password');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if (in_array($page, ['login', 'register', 'forgot-password'], true) && ($user = Auth::user())) redirect(Auth::dashboard($user['role']));
            return;
        }
        match ($page) {
            'register' => $this->register(), 'login' => $this->login(), 'logout' => $this->logout(),
            'forgot-password' => $this->forgot(), 'reset-password' => $this->reset(), 'profile' => $this->profile(), default => null,
        };
    }

    private function limit(string $scope, int $ipMaximum, ?string $email = null, int $emailMaximum = 5): bool
    {
        $allowed = RateLimiter::consume($scope . '-ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $ipMaximum);
        if ($allowed && $email !== null) $allowed = RateLimiter::consume($scope . '-email', $email, $emailMaximum);
        if (!$allowed) {
            http_response_code(429); header('Retry-After: 900');
            $this->errors[] = 'Too many attempts. Please try again in 15 minutes.';
        }
        return $allowed;
    }

    private function email(): string
    {
        $email = strtolower(trim(input('email')));
        $this->old['email'] = mb_substr($email, 0, 254);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $this->errors[] = 'Enter a valid email address.';
        return $email;
    }

    private function password(): string
    {
        $password = input('password');
        if ($error = Auth::passwordError($password)) $this->errors[] = $error;
        if ($password !== input('password_confirmation')) $this->errors[] = 'The password confirmation does not match.';
        return $password;
    }

    private function register(): void
    {
        if (!$this->limit('register', 10)) return;
        $name = trim(input('name')); $this->old['name'] = mb_substr($name, 0, 100);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name)) $this->errors[] = 'Enter your name using 2–100 characters.';
        $email = $this->email(); $password = $this->password();
        if ($this->errors) return;
        try {
            $id = Database::transaction(function (\PDO $pdo) use ($name, $email, $password): int {
                $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'driver')")->execute([$name, $email, Auth::hashPassword($password)]);
                $id = (int) $pdo->lastInsertId(); Audit::record('account.registered', $id, $id); return $id;
            });
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            $this->errors[] = 'Unable to create an account with those details. Try logging in or recovering your account.'; return;
        }
        Auth::login(['id' => $id, 'session_version' => 1]);
        flash('Welcome to Park n Go. Your driver account is ready.'); redirect('dashboard');
    }

    private function login(): void
    {
        $email = strtolower(trim(input('email'))); $this->old['email'] = mb_substr($email, 0, 254);
        if (!$this->limit('login', 40, $email, 10)) return;
        $statement = Database::connection()->prepare('SELECT id, password_hash, status, role, session_version FROM users WHERE email = ?');
        $statement->execute([strlen($email) <= 254 ? $email : '']); $user = $statement->fetch();
        $dummy = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $password = input('password');
        $valid = password_verify(substr($password, 0, 72), $user['password_hash'] ?? $dummy);
        if (!$user || !$valid || $user['status'] !== 'active' || strlen($password) > 72 || str_contains($password, "\0")) {
            Audit::record('login.failed'); $this->errors[] = 'The email or password is incorrect, or this account is unavailable.'; return;
        }
        $accepted = Database::transaction(function (\PDO $pdo) use ($user, $password): bool {
            $lock = $pdo->prepare('SELECT password_hash, status, session_version FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$user['id']]); $current = $lock->fetch();
            if (!$current || $current['status'] !== 'active' || $current['password_hash'] !== $user['password_hash'] || (int) $current['session_version'] !== (int) $user['session_version']) return false;
            if (password_needs_rehash($current['password_hash'], PASSWORD_DEFAULT, ['cost' => 12])) $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([Auth::hashPassword($password), $user['id']]);
            Audit::record('login.succeeded', (int) $user['id'], (int) $user['id']); return true;
        });
        if (!$accepted) { $this->errors[] = 'Account access changed. Please try logging in again.'; return; }
        Auth::login($user); redirect(Auth::dashboard($user['role']));
    }

    private function logout(): void
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        // Clear credentials first. An unavailable audit database must never prevent logout.
        Auth::logout();
        if ($userId) {
            try { Audit::record('logout', $userId, $userId); }
            catch (\Throwable $error) { error_log('Park n Go logout audit could not be stored.'); }
        }
        flash('You have been logged out.'); redirect('login');
    }

    private function forgot(): void
    {
        $email = $this->email();
        if (!$this->limit('recovery', 20, $email, 3) || $this->errors) return;
        try { PasswordReset::request($email); }
        catch (\RuntimeException $error) {
            if ($error instanceof \PDOException) throw $error;
            error_log('Park n Go recovery delivery failed. Check private mail configuration.');
        }
        flash(PasswordReset::MESSAGE); redirect('forgot-password');
    }

    private function reset(): void
    {
        if (!$this->limit('reset', 20)) return;
        $password = $this->password(); if ($this->errors) return;
        if (!PasswordReset::reset($_SESSION['reset_token'] ?? '', $password)) { $this->errors[] = 'This reset link is invalid, expired, or already used. Request a new link.'; return; }
        Auth::logout(); flash('Your password has been updated. Please log in with your new password.'); redirect('login');
    }

    private function profile(): void
    {
        $user = Auth::user(); if (!$user || !$this->limit('profile', 30)) return;
        $name = trim(input('name')); $phone = trim(input('phone'));
        $this->old = ['name' => mb_substr($name, 0, 100), 'phone' => mb_substr($phone, 0, 24)];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name)) $this->errors[] = 'Enter a name using 2–100 characters.';
        if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,24}$/D', $phone)) $this->errors[] = 'Enter a valid phone number, or leave it blank.';
        if ($this->errors) return;
        Database::transaction(function (\PDO $pdo) use ($user, $name, $phone): void {
            $pdo->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $user['id']]);
            Audit::record('profile.updated', (int) $user['id'], (int) $user['id']);
        });
        flash('Your profile has been updated.'); redirect('profile');
    }
}
