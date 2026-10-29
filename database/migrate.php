<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Environment;

try {
    $name = Environment::get('DB_DATABASE', 'parkngo_db');
    $server = Database::connect(false);
    $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = Database::connection();
    if (!(int) $pdo->query("SELECT GET_LOCK('parkngo_migration', 10)")->fetchColumn()) throw new RuntimeException('Migration is already running.');
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        foreach (glob(__DIR__ . '/migrations/*.sql') as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            $check = $pdo->prepare('SELECT checksum FROM migrations WHERE version = ?');
            $check->execute([$version]);
            $existing = $check->fetchColumn();
            if ($existing) {
                if (!hash_equals($existing, $checksum)) throw new RuntimeException('Applied migration was changed: ' . $version);
                echo "Already applied: $version\n";
                continue;
            }
            // MySQL DDL implicitly commits. Never rerun a partially applied migration blindly.
            foreach (explode(';', file_get_contents($file)) as $sql) {
                if (trim($sql) !== '') $pdo->exec($sql);
            }
            $pdo->prepare('INSERT INTO migrations (version, checksum) VALUES (?, ?)')->execute([$version, $checksum]);
            echo "Applied: $version\n";
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('parkngo_migration')");
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: " . $error->getMessage() . "\nNo automatic destructive recovery was performed.\n");
    exit(1);
}
