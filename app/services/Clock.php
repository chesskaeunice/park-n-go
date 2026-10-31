<?php
declare(strict_types=1);

namespace ParkNGo\Services;

/**
 * UTC application clock with second precision. Billing decisions read this clock so boundary tests can
 * use controlled timestamps; freezing is refused outside APP_ENV=local with an isolated *_test database.
 */
final class Clock
{
    private static ?\DateTimeImmutable $frozen = null;

    public static function now(): \DateTimeImmutable
    {
        if (self::$frozen !== null) return self::$frozen;
        // Set only by tests/fixtures/clock-router.php for the PHP development server.
        if (PHP_SAPI === 'cli-server' && defined('PARKNGO_TEST_CLOCK') && self::testEnvironment()) {
            return new \DateTimeImmutable((string) constant('PARKNGO_TEST_CLOCK'), new \DateTimeZone('UTC'));
        }
        return new \DateTimeImmutable('@' . time());
    }

    public static function freeze(?string $utc): void
    {
        if (!self::testEnvironment()) throw new \RuntimeException('The clock can only be frozen in an isolated test database.');
        self::$frozen = $utc === null ? null : new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private static function testEnvironment(): bool
    {
        return Environment::get('APP_ENV', 'local') === 'local' && str_ends_with(Environment::get('DB_DATABASE'), '_test');
    }
}
