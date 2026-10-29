<?php
declare(strict_types=1);

namespace ParkNGo\Services;

final class Auth
{
    private static bool $loaded = false;
    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        if (empty($_SESSION['user_id'])) return null;
        $statement = Database::connection()->prepare('SELECT id, name, email, phone, role, status, session_version FROM users WHERE id = ?');
        $statement->execute([$_SESSION['user_id']]);
        $user = $statement->fetch();
        if (!$user || $user['status'] !== 'active' || (int) $user['session_version'] !== (int) ($_SESSION['session_version'] ?? -1)
            || time() - (int) ($_SESSION['last_activity'] ?? 0) > 1800
            || time() - (int) ($_SESSION['authenticated_at'] ?? 0) > 28800) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        return self::$user = $user;
    }

    public static function login(array $user): void
    {
        if (!session_regenerate_id(true)) throw new \RuntimeException('Unable to renew the session.');
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'session_version' => (int) $user['session_version'],
            'authenticated_at' => time(),
            'last_activity' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
        self::$user = null;
        self::$loaded = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            session_destroy();
            throw new \RuntimeException('Unable to renew the cleared session.');
        }
        self::$user = null;
        self::$loaded = true;
    }

    public static function dashboard(string $role): string
    {
        return match ($role) {
            'admin' => 'admin-dashboard',
            'attendant' => 'attendant-dashboard',
            default => 'dashboard',
        };
    }

    public static function passwordError(string $password): ?string
    {
        // PASSWORD_DEFAULT uses bcrypt on PHP 8.2; reject silent byte truncation.
        return strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
            ? 'Use a password between 12 and 72 bytes (usually 12–72 characters).'
            : null;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }
}
