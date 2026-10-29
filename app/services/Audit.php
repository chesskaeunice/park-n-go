<?php
declare(strict_types=1);

namespace ParkNGo\Services;

final class Audit
{
    public static function record(string $action, ?int $actorId = null, ?int $subjectId = null, string $subjectType = 'user'): void
    {
        $statement = Database::connection()->prepare('INSERT INTO audit_logs (actor_id, action, subject_type, subject_id, ip_hash) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$actorId, $action, $subjectType, $subjectId, hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli')]);
    }
}
