<?php
declare(strict_types=1);

// Operator bootstrap for sensitive admin permissions (audited with no web actor):
//   php database/admin-permission.php list
//   php database/admin-permission.php grant|revoke <admin-email> <permission> "<reason>"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Admin\AdminPermissions;

try {
    $command = $argv[1] ?? '';
    if ($command === 'list') {
        foreach (AdminPermissions::admins() as $admin) echo $admin['email'] . ' [' . $admin['status'] . ']: ' . (implode(', ', array_keys($admin['permissions'])) ?: 'no sensitive permissions') . "\n";
        exit(0);
    }
    if (!in_array($command, ['grant', 'revoke'], true) || count($argv) !== 5) {
        fwrite(STDERR, "Usage: php database/admin-permission.php list | grant|revoke <admin-email> <permission> \"<reason>\"\nPermissions: " . implode(', ', array_keys(AdminPermissions::ALL)) . "\n");
        exit(1);
    }
    $find = Database::connection()->prepare('SELECT id FROM users WHERE email=?'); $find->execute([$argv[2]]); $id = $find->fetchColumn();
    if (!$id) throw new InvalidArgumentException('No user has that email address.');
    AdminPermissions::change(null, (int) $id, $argv[3], $command === 'grant', 'CLI: ' . $argv[4]);
    echo ($command === 'grant' ? 'Granted' : 'Revoked') . " {$argv[3]} for {$argv[2]}.\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof InvalidArgumentException ? $error->getMessage() : 'Permission change failed: ' . get_class($error)) . "\n");
    exit(1);
}
