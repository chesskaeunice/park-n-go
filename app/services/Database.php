<?php
declare(strict_types=1);

namespace ParkNGo\Services;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) self::$connection = self::connect();
        return self::$connection;
    }

    public static function connect(bool $withDatabase = true): PDO
    {
        $name = Environment::get('DB_DATABASE', 'parkngo_db');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new \RuntimeException('Invalid database name.');
        $dsn = 'mysql:host=' . Environment::get('DB_HOST', '127.0.0.1')
            . ';port=' . (int) Environment::get('DB_PORT', '3306')
            . ($withDatabase ? ';dbname=' . $name : '') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, Environment::get('DB_USERNAME', 'root'), Environment::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
