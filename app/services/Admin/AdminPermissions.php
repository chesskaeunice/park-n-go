<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\Database;

/**
 * Explicit sensitive permissions layered on the single admin role. Being an admin grants none of them.
 * Checks run on the server inside the action's transaction, with the permission row share-locked so a
 * concurrent revocation is honoured.
 */
final class AdminPermissions
{
    public const ALL = [
        'manage_pricing' => 'Manage pricing',
        'approve_refunds' => 'Approve refunds',
        'active_session_exception' => 'Active session exceptions',
        'manage_permissions' => 'Manage permissions',
    ];

    public static function label(string $permission): string
    {
        return self::ALL[$permission] ?? $permission;
    }

    public static function has(int $userId, string $permission, ?\PDO $pdo = null, bool $lock = false): bool
    {
        if (!isset(self::ALL[$permission])) return false;
        $find = ($pdo ?? Database::connection())->prepare("SELECT 1 FROM users u JOIN admin_permissions p ON p.user_id=u.id AND p.permission=? AND p.revoked_at IS NULL WHERE u.id=? AND u.role='admin' AND u.status='active'" . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $find->execute([$permission, $userId]);
        return (bool) $find->fetchColumn();
    }

    /** Call inside the action's transaction. */
    public static function authorize(\PDO $pdo, int $userId, string $permission): void
    {
        if (!self::has($userId, $permission, $pdo, true)) throw new PermissionDenied($permission);
    }

    /** @return array<string, bool> */
    public static function forUser(int $userId): array
    {
        $result = array_fill_keys(array_keys(self::ALL), false);
        $find = Database::connection()->prepare("SELECT p.permission FROM admin_permissions p JOIN users u ON u.id=p.user_id WHERE p.user_id=? AND p.revoked_at IS NULL AND u.role='admin' AND u.status='active'");
        $find->execute([$userId]);
        foreach ($find->fetchAll(\PDO::FETCH_COLUMN) as $permission) if (isset($result[$permission])) $result[$permission] = true;
        return $result;
    }

    public static function admins(): array
    {
        $admins = Database::connection()->query("SELECT id,name,email,status FROM users WHERE role='admin' ORDER BY name,id")->fetchAll();
        $grants = Database::connection()->query('SELECT user_id,permission,granted_at FROM admin_permissions WHERE revoked_at IS NULL')->fetchAll();
        $map = [];
        foreach ($grants as $grant) $map[(int) $grant['user_id']][$grant['permission']] = $grant['granted_at'];
        foreach ($admins as &$admin) $admin['permissions'] = $map[(int) $admin['id']] ?? [];
        return $admins;
    }

    /** Grant or revoke. $actorId null means the operator CLI bootstrap (database/admin-permission.php). */
    public static function change(?int $actorId, int $targetId, string $permission, bool $grant, string $reason): void
    {
        if (!isset(self::ALL[$permission])) throw new \InvalidArgumentException('Choose a valid permission.');
        $reason = Reason::require($reason);
        Database::transaction(function (\PDO $pdo) use ($actorId, $targetId, $permission, $grant, $reason): void {
            if ($actorId !== null) {
                self::authorize($pdo, $actorId, 'manage_permissions');
                // Separation of duties: nobody changes their own sensitive permissions.
                if ($actorId === $targetId) throw new \InvalidArgumentException('Another administrator must change your permissions.');
            }
            $target = $pdo->prepare('SELECT id,role,status FROM users WHERE id=? FOR UPDATE'); $target->execute([$targetId]); $target = $target->fetch();
            if (!$target || $target['role'] !== 'admin' || $target['status'] !== 'active') throw new \InvalidArgumentException('Permissions can only be changed for active administrator accounts.');
            $current = $pdo->prepare('SELECT granted_at,revoked_at FROM admin_permissions WHERE user_id=? AND permission=? FOR UPDATE'); $current->execute([$targetId, $permission]); $row = $current->fetch();
            $before = ['permission' => $permission, 'granted' => $row !== false && $row['revoked_at'] === null];
            if ($before['granted'] === $grant) throw new \InvalidArgumentException($grant ? 'That permission is already granted.' : 'That permission is not currently granted.');
            if ($grant) $pdo->prepare('INSERT INTO admin_permissions (user_id,permission,granted_by,granted_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE granted_by=VALUES(granted_by),granted_at=UTC_TIMESTAMP(),revoked_by=NULL,revoked_at=NULL')->execute([$targetId, $permission, $actorId]);
            else $pdo->prepare('UPDATE admin_permissions SET revoked_by=?,revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND permission=? AND revoked_at IS NULL')->execute([$actorId, $targetId, $permission]);
            AdminAudit::record($pdo, $actorId, $grant ? 'permission.granted' : 'permission.revoked', 'user', $targetId, null, $reason, $before, ['permission' => $permission, 'granted' => $grant]);
        });
    }
}
