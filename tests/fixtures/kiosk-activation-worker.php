<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use ParkNGo\Services\KioskService;

try {
    KioskService::activate((string) ($argv[1] ?? ''));
    echo "success\n";
} catch (Throwable $error) {
    echo "rejected\n";
}
