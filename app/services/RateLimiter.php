<?php
declare(strict_types=1);

namespace ParkNGo\Services;

final class RateLimiter
{
    /** Atomic fixed windows; store only hashed identifiers, never submitted credentials. */
    public static function consume(string $scope, string $identifier, int $maximum, int $seconds = 900): bool
    {
        $key = hash('sha256', $scope . ':' . $identifier);
        $pdo = Database::connection();
        // Two first requests for a new bucket can deadlock (INSERT IGNORE vs. locking read). The transaction has no
        // other effects, so retrying after InnoDB rolls it back is safe.
        for ($attempt = 1; ; $attempt++) {
            try { return self::bucket($pdo, $key, $maximum, $seconds); }
            catch (\PDOException $error) { if ($attempt >= 3 || (int) ($error->errorInfo[1] ?? 0) !== 1213) throw $error; usleep(random_int(5000, 25000)); }
        }
    }

    private static function bucket(\PDO $pdo, string $key, int $maximum, int $seconds): bool
    {
        return Database::transaction(function () use ($pdo, $key, $maximum, $seconds): bool {
            $insert = $pdo->prepare('INSERT IGNORE INTO rate_limits (bucket_key, attempts, expires_at) VALUES (?, 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))');
            $insert->execute([$key, $seconds]);
            $select = $pdo->prepare('SELECT attempts, expires_at <= UTC_TIMESTAMP() AS expired FROM rate_limits WHERE bucket_key = ? FOR UPDATE');
            $select->execute([$key]);
            $bucket = $select->fetch();
            if ($bucket['expired']) {
                $pdo->prepare('UPDATE rate_limits SET attempts = 1, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE bucket_key = ?')->execute([$seconds, $key]);
                return true;
            }
            if ((int) $bucket['attempts'] >= $maximum) return false;
            $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket_key = ?')->execute([$key]);
            return true;
        });
    }
}
