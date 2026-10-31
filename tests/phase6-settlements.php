<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

use ParkNGo\Services\Database;
use ParkNGo\Services\Parking\OvertimeService;
use ParkNGo\Services\Payments\PaymentService;

$pdo = Database::connection();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
    $checks++; echo "PASS {$message}\n";
};

$tables = $pdo->query("SHOW TABLES LIKE 'parking_overtime_settlements'")->fetchColumn();
$check($tables === 'parking_overtime_settlements', 'overtime settlement ledger migration is installed');
$tables = $pdo->query("SHOW TABLES LIKE 'parking_extensions'")->fetchColumn();
$check($tables === 'parking_extensions', 'extension ledger migration is installed');

$service = new OvertimeService();
$session = ['id' => 1, 'paid_until' => '2026-01-01 00:00:00', 'overtime_hour_centavos' => 1000];
$state = $service->calculate($session, '2026-01-01 00:00:01');
$check($state['elapsed_seconds'] === 1 && $state['estimated_centavos'] === 1, 'overtime uses elapsed seconds and rounds up to one centavo');
$state = $service->calculate($session, '2026-01-01 00:30:00');
$check($state['elapsed_seconds'] === 1800 && $state['estimated_centavos'] === 500, 'overtime charges exact prorated integer-centavo amount');
$session['grace_until'] = '2026-01-01 00:15:00';
$state = $service->calculate($session, '2026-01-01 00:10:00');
$check($state['elapsed_seconds'] === 0 && $state['payment_required'] === false, 'active verified grace suppresses overtime during grace window');
$state = $service->calculate($session, '2026-01-01 00:15:00');
$check($state['elapsed_seconds'] === 0 && $state['payment_required'] === false, 'grace boundary is inclusive at exactly fifteen minutes');
$state = $service->calculate($session, '2026-01-01 00:15:01');
$check($state['elapsed_seconds'] === 1 && $state['estimated_centavos'] === 1, 'overtime resumes only for newly accrued time after grace');

$run = strtoupper(bin2hex(random_bytes(5)));
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?, 'driver')")->execute(['Settlement Driver', 'settlement-' . strtolower($run) . '@parkngo.test', password_hash('fixture', PASSWORD_DEFAULT)]);
$user = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_facilities (name,address,city) VALUES (?,?,?)')->execute(['Settlement Facility', 'Address', 'City']); $facility=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_floors (facility_id,label) VALUES (?,?)')->execute([$facility,'Ground']); $floor=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_slots (floor_id,label) VALUES (?,?)')->execute([$floor,'S-1']); $slot=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO parking_rates (facility_id,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,effective_from) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$facility,5000,2000,1000]); $rate=(int)$pdo->lastInsertId();
$start=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-2 hours'); $paidUntil=$start->modify('+60 minutes');
$pdo->prepare("INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,status,estimated_centavos,rate_snapshot) VALUES (?,?,?,?,?,?, 'confirmed',?,?)")->execute(['SETTLE-'.$run,$user,$slot,$rate,$start->format('Y-m-d H:i:s'),$start->modify('+3 hours')->format('Y-m-d H:i:s'),5000,json_encode(['version'=>1])]); $reservation=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,purchased_minutes,paid_until,status) VALUES (?,?,?,60,?,'active')")->execute([$reservation,$user,$start->format('Y-m-d H:i:s'),$paidUntil->format('Y-m-d H:i:s')]); $parkingSession=(int)$pdo->lastInsertId();
$reference='PNGO-SETTLE-'.$run; $checkout='maya-settle-'.$run;
$pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,'OVERTIME_SETTLEMENT',1000,'PHP',?,?, 'checkout_created',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))")->execute([$reference,$user,$parkingSession,$checkout,$reference]); $attempt=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO parking_overtime_settlements (parking_session_id,payment_attempt_id,interval_start,interval_end,billed_seconds,rate_centavos,amount_centavos) VALUES (?,?,?,?,?,?,?)")->execute([$parkingSession,$attempt,$paidUntil->format('Y-m-d H:i:s'),(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),3600,1000,1000]);
$payload=['id'=>$checkout,'requestReferenceNumber'=>$reference,'paymentStatus'=>'PAYMENT_SUCCESS','totalAmount'=>['value'=>'10.00','currency'=>'PHP']]; $paymentService=new PaymentService(); $paymentService->handleVerifiedWebhook($payload);
$row=$pdo->prepare('SELECT status,grace_until FROM parking_overtime_settlements WHERE payment_attempt_id=?'); $row->execute([$attempt]); $grace=$row->fetch();
$check($grace['status']==='verified' && is_string($grace['grace_until']), 'verified overtime payment creates one settlement and grace window');
$firstGrace=$grace['grace_until']; $paymentService->handleVerifiedWebhook($payload); $row->execute([$attempt]); $check($row->fetch()['grace_until']===$firstGrace, 'duplicate overtime webhook does not renew grace');
$lateReference='PNGO-LATE-'.$run; $lateCheckout='maya-late-'.$run; $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_checkout_id,maya_request_reference,local_status) VALUES (?,?,?,'OVERTIME_SETTLEMENT',1000,'PHP',?,?, 'expired')")->execute([$lateReference,$user,$parkingSession,$lateCheckout,$lateReference]);
$latePayload=['id'=>$lateCheckout,'requestReferenceNumber'=>$lateReference,'paymentStatus'=>'PAYMENT_SUCCESS','totalAmount'=>['value'=>'10.00','currency'=>'PHP']]; $paymentService->handleVerifiedWebhook($latePayload); $late=$pdo->prepare('SELECT local_status FROM payment_attempts WHERE reference=?'); $late->execute([$lateReference]); $check($late->fetchColumn()==='reconciliation', 'late success after expiry enters reconciliation');
try { $paymentService->startExtensionPayment($user, $parkingSession, [4]); $check(false, 'extension rejects unapproved block'); } catch (InvalidArgumentException) { $check(true, 'extension rejects unapproved block'); }
try { $paymentService->startExtensionPayment($user, $parkingSession, [1,2,3,1]); $check(false, 'extension rejects totals above six hours'); } catch (InvalidArgumentException) { $check(true, 'extension rejects totals above six hours'); }
$pdo->prepare("UPDATE parking_overtime_settlements SET grace_until=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE payment_attempt_id=?")->execute([$attempt]);
try { $paymentService->startExtensionPayment($user, $parkingSession, [1]); $check(false, 'extension rejects overdue session'); } catch (InvalidArgumentException) { $check(true, 'extension rejects overdue session'); }
$futurePaidUntil=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+30 minutes')->format('Y-m-d H:i:s'); $pdo->prepare('UPDATE parking_sessions SET paid_until=? WHERE id=?')->execute([$futurePaidUntil,$parkingSession]);
try { $paymentService->startExtensionPayment($user, $parkingSession, [1,3]); } catch (Throwable) { /* Maya is intentionally unavailable in this isolated test. */ }
$extension=$pdo->query("SELECT duration_minutes,amount_centavos,status FROM parking_extensions ORDER BY id DESC LIMIT 1")->fetch(); $check((int)$extension['duration_minutes']===240 && (int)$extension['amount_centavos']===8000 && $extension['status']==='failed', 'approved extension blocks are priced server-side and failed checkout is closed');

echo "{$checks} Phase 6 settlement checks passed.\n";
