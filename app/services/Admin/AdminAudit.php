<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\Database;

/**
 * Append-only administrative audit trail (admin_audit_events). The table rejects UPDATE and DELETE through
 * triggers. State snapshots are curated by callers; secret-like keys are dropped defensively.
 */
final class AdminAudit
{
    private static ?string $correlation = null;

    /** One correlation id per request (or CLI run) links every event a single admin action produced. */
    public static function correlation(): string
    {
        return self::$correlation ??= bin2hex(random_bytes(16));
    }

    public static function record(?\PDO $pdo, ?int $actorId, string $action, string $targetType, ?int $targetId, ?int $facilityId = null,
        ?string $reason = null, ?array $before = null, ?array $after = null, string $outcome = 'succeeded'): void
    {
        $pdo ??= Database::connection();
        $pdo->prepare('INSERT INTO admin_audit_events (actor_id,action,target_type,target_id,facility_id,reason,before_state,after_state,outcome,correlation_id,ip_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$actorId, $action, $targetType, $targetId, $facilityId, $reason === null ? null : mb_substr($reason, 0, 1000),
                $before === null ? null : self::encode($before), $after === null ? null : self::encode($after), $outcome, self::correlation(),
                hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli')]);
    }

    private static function encode(array $state): string
    {
        $clean = static function (array $value) use (&$clean): array {
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/token|secret|password|credential|hash|redirect_url|cookie/i', $key)) { unset($value[$key]); continue; }
                if (is_array($item)) $value[$key] = $clean($item);
            }
            return $value;
        };
        return json_encode($clean($state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
