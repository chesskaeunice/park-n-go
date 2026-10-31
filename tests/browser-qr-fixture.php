<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = \ParkNGo\Services\Database::connection();
$admin = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id DESC LIMIT 1")->fetchColumn();
$facility = (int) $pdo->query('SELECT id FROM parking_facilities WHERE is_active=1 ORDER BY id DESC LIMIT 1')->fetchColumn();
$kiosk = \ParkNGo\Services\KioskService::create($admin, $facility, 'Browser QR Kiosk');
$uri = (new \ParkNGo\Services\Qr\TicketService())->qrDataUri('PNGO-BROWSER-QR-TEST');
$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'parkngo-browser-qr.png';
file_put_contents($path, base64_decode(substr($uri, strpos($uri, ',') + 1)));
echo $kiosk['code'] . '|' . $path;
