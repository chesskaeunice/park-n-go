<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/app/bootstrap.php';

try {
    $count=(new \ParkNGo\Services\Parking\ReservationService())->expire(500);
    echo "Expired $count pending reservations. Run every minute for prompt history updates.\n";
} catch (Throwable $error) { fwrite(STDERR,'Hold expiry failed: '.get_class($error).". Check the database configuration.\n");exit(1); }
