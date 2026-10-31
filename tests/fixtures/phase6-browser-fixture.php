<?php
declare(strict_types=1);

// CLI helper for tests/phase6-browser.mjs. Prints JSON only; runs against the same isolated *_test database.
ob_start();
require __DIR__ . '/phase6-support.php';
ob_end_clean();

use ParkNGo\Services\KioskService;
use ParkNGo\Services\Qr\TicketService;

$command = $argv[1] ?? '';
$out = static function (array $data): never { echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); exit(0); };
if ($command === 'setup') {
    $place = p6_facility(2000, 2500); $admin = p6_user('admin');
    $extend = p6_session($place); $qr = p6_session($place); $manual = p6_session($place);
    $png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p6-browser-' . p6_uid() . '.png';
    $uri = (new TicketService())->qrDataUri($qr['token']);
    file_put_contents($png, base64_decode(substr($uri, strpos($uri, ',') + 1)));
    $kiosk = KioskService::create($admin['id'], $place['facility'], 'Browser Gate');
    $out(['driver' => $extend['driver'], 'extendSession' => $extend['session'], 'paidUntil' => p6_session_row($extend['session'])['paid_until'],
        'qrSession' => $qr['session'], 'qrToken' => $qr['token'], 'qrPng' => $png, 'manualSession' => $manual['session'], 'manualCode' => $manual['manual'],
        'kioskCode' => $kiosk['code'], 'invalidPng' => BASE_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'icon-192.png']);
}
if ($command === 'latest') {
    $attempt = p6_latest_attempt((int) ($argv[2] ?? 0));
    $out($attempt ? ['reference' => $attempt['reference'], 'status' => $attempt['local_status'], 'type' => $attempt['payment_type'], 'amount' => (int) $attempt['amount_centavos'], 'duration' => (int) (p6_ledger($attempt)['duration_minutes'] ?? 0), 'checkout' => $attempt['maya_checkout_id']] : []);
}
if ($command === 'provider') { p6_fake_status((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')); $out(['ok' => true]); }
if ($command === 'session') { $row = p6_session_row((int) ($argv[2] ?? 0)); $out(['status' => $row['status'], 'paid_until' => $row['paid_until'], 'exit_at' => $row['exit_at']]); }
fwrite(STDERR, "Unknown command.\n"); exit(1);
