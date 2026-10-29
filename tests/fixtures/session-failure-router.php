<?php
declare(strict_types=1);

// Dedicated development-server fixture. Never use this as the application router.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
session_set_save_handler(
    static fn(string $path, string $name): bool => false,
    static fn(): bool => true,
    static fn(string $id): string => '',
    static fn(string $id, string $data): bool => false,
    static fn(string $id): bool => true,
    static fn(int $seconds): int => 0,
);
require dirname(__DIR__, 2) . '/public/index.php';
