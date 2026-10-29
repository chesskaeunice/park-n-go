<?php
declare(strict_types=1);
namespace ParkNGo\Services;

final class PasswordReset
{
    public const MESSAGE = 'If an active account matches that email, a password reset link will be sent. Check your inbox. In local development, check the private mail outbox.';

    public static function request(string $email): void
    {
        $delivery = Database::transaction(function (\PDO $pdo) use ($email): ?array {
            $statement = $pdo->prepare("SELECT id, email FROM users WHERE email = ? AND status = 'active' FOR UPDATE");
            $statement->execute([$email]);
            $user = $statement->fetch();
            if (!$user) return null;
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))')->execute([$user['id'], hash('sha256', $token)]);
            Audit::record('password_reset.requested', null, (int) $user['id']);
            return ['email' => $user['email'], 'token' => $token];
        });
        if ($delivery) self::deliver($delivery['email'], $delivery['token']);
    }

    private static function deliver(string $email, string $token): void
    {
        $base = rtrim(Environment::get('APP_URL', 'http://localhost/park-n-go/public'), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) throw new \RuntimeException('Invalid recovery URL configuration.');
        $link = $base . '/index.php?page=reset-password&token=' . $token;
        $body = "A password reset was requested for your Park n Go account.\n\n$link\n\nThis single-use link expires in 30 minutes. Ignore this message if you did not request it.\n";
        $transport = Environment::get('MAIL_TRANSPORT', 'log');
        if ($transport === 'log' && Environment::get('APP_ENV', 'local') === 'local') {
            $directory = BASE_PATH . '/storage/mail';
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create mail outbox.');
            $path = $directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.eml';
            if (file_put_contents($path, "To: $email\nSubject: Reset your Park n Go password (LOCAL ONLY)\n\n$body", LOCK_EX) === false) throw new \RuntimeException('Cannot write local mail.');
            return;
        }
        if ($transport === 'mail') {
            $from = Environment::get('MAIL_FROM');
            if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) throw new \RuntimeException('Invalid mail sender.');
            if (Environment::get('APP_ENV', 'local') !== 'local' && parse_url($base, PHP_URL_SCHEME) !== 'https') throw new \RuntimeException('HTTPS recovery URL required.');
            if (!mail($email, 'Reset your Park n Go password', $body, ['From' => $from, 'Content-Type' => 'text/plain; charset=UTF-8'])) throw new \RuntimeException('Mail transport rejected recovery email.');
            return;
        }
        throw new \RuntimeException('A mail transport must be configured.');
    }

    public static function reset(string $token, string $password): bool
    {
        if (Auth::passwordError($password) !== null || !preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $hash = Auth::hashPassword($password);
        return Database::transaction(function (\PDO $pdo) use ($token, $hash): bool {
            // Lock user before tokens in both issuance and redemption to serialize resets.
            $find = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = ?');
            $find->execute([hash('sha256', $token)]);
            $userId = $find->fetchColumn();
            if (!$userId) return false;
            $user = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' FOR UPDATE");
            $user->execute([$userId]);
            if (!$user->fetch()) return false;
            $check = $pdo->prepare('SELECT id FROM password_reset_tokens WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
            $check->execute([$userId, hash('sha256', $token)]);
            if (!$check->fetch()) return false;
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([$hash, $userId]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
            Audit::record('password_reset.completed', (int) $userId, (int) $userId);
            return true;
        });
    }
}
