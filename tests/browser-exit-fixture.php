<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Auth;
use ParkNGo\Services\Database;
use ParkNGo\Services\Qr\TicketService;

$pdo = Database::connection();
$run = strtoupper(bin2hex(random_bytes(5)));
$password = Auth::hashPassword('browser-fixture');
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'driver')")->execute(['Browser Exit Driver', 'browser-exit-' . strtolower($run) . '@parkngo.test', $password]); $driver=(int)$pdo->lastInsertId();
$facility=(int)$pdo->query('SELECT id FROM parking_facilities WHERE is_active=1 ORDER BY id DESC LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO parking_floors (facility_id,label) VALUES (?,?)')->execute([$facility,'Browser Ground']); $floor=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$floor,'B-' . $run]); $slot=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_rates (facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$facility,5000,2000,2500]); $rate=(int)$pdo->lastInsertId();
$now=new DateTimeImmutable('now',new DateTimeZone('UTC')); $token='PNGO-' . strtoupper(bin2hex(random_bytes(16))); $manual='MANUAL-' . strtoupper($run);
$pdo->prepare("INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,status,estimated_centavos,rate_snapshot) VALUES (?,?,?,?,?,?, 'confirmed',?,?)")->execute(['BROWSER-'.$run,$driver,$slot,$rate,$now->modify('-10 minutes')->format('Y-m-d H:i:s'),$now->modify('+50 minutes')->format('Y-m-d H:i:s'),5000,json_encode(['version'=>1])]); $reservation=(int)$pdo->lastInsertId();
$reference='PAY-BROWSER-'.$run; $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,local_status,verified_at) VALUES (?,?,?,'INITIAL_RESERVATION',5000,'PHP','verified',UTC_TIMESTAMP())")->execute([$reference,$driver,$reservation]);
$pdo->prepare("INSERT INTO qr_tickets (reservation_id,token_hash,manual_code_hash,status,expires_at,last_issued_at) VALUES (?,?,?,'active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR),UTC_TIMESTAMP())")->execute([$reservation,hash('sha256',$token),hash('sha256',$manual)]); $ticket=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,purchased_minutes,paid_until,status) VALUES (?,?,UTC_TIMESTAMP(),60,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),'active')")->execute([$reservation,$driver]);
$uri=(new TicketService())->qrDataUri($token); $path=sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'parkngo-browser-exit-' . strtolower($run) . '.png'; file_put_contents($path,base64_decode(substr($uri,strpos($uri,',')+1)));
echo $path . '|' . $manual . '|' . $token;
