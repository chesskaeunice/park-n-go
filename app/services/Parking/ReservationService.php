<?php
declare(strict_types=1);
namespace ParkNGo\Services\Parking;

use ParkNGo\Services\{Audit, Database};

final class ReservationService
{
    private array $config;
    public function __construct() { $this->config = require BASE_PATH . '/config/reservations.php'; }

    private function transaction(callable $callback): mixed
    {
        for ($attempt=0;;$attempt++) {
            try { return Database::transaction($callback); }
            catch (\PDOException $error) {
                if (!in_array((int) ($error->errorInfo[1] ?? 0), [1205,1213], true) || $attempt >= 2) throw $error;
                usleep(random_int(10000,40000));
            }
        }
    }

    private function driver(\PDO $pdo, int $id, ?int $version = null): void
    {
        $statement = $pdo->prepare('SELECT role,status,session_version FROM users WHERE id=? FOR UPDATE');$statement->execute([$id]);$user=$statement->fetch();
        if (!$user || $user['role'] !== 'driver' || $user['status'] !== 'active' || ($version !== null && (int)$user['session_version'] !== $version)) throw new \OutOfBoundsException('Your driver account is unavailable. Please log in again.');
    }

    private function context(int $slotId, bool $lock = false): array
    {
        $pdo=Database::connection();
        $find=$pdo->prepare('SELECT f.facility_id,s.floor_id FROM parking_slots s JOIN parking_floors f ON f.id=s.floor_id WHERE s.id=?');$find->execute([$slotId]);$parent=$find->fetch();
        if (!$parent) throw new \OutOfBoundsException('Parking space not found.');
        $suffix=$lock?' FOR UPDATE':'';
        $find=$pdo->prepare('SELECT * FROM parking_facilities WHERE id=?'.$suffix);$find->execute([$parent['facility_id']]);$facility=$find->fetch();
        $find=$pdo->prepare('SELECT * FROM parking_floors WHERE id=?'.$suffix);$find->execute([$parent['floor_id']]);$floor=$find->fetch();
        $find=$pdo->prepare('SELECT * FROM parking_slots WHERE id=?'.$suffix);$find->execute([$slotId]);$slot=$find->fetch();
        if (!$facility || !$floor || !$slot || (int)$slot['floor_id']!==(int)$floor['id'] || (int)$floor['facility_id']!==(int)$facility['id']) throw new \OutOfBoundsException('Parking space not found.');
        return compact('facility','floor','slot');
    }

    private function usable(array $context): void
    {
        if (!$context['facility']['is_active'] || !$context['floor']['is_active'] || $context['slot']['status'] !== 'available') throw new ReservationConflict('This parking space is unavailable. Choose another space.');
    }

    private function price(array $context, AvailabilityWindow $window, bool $lock = false): array
    {
        $find=Database::connection()->prepare('SELECT * FROM parking_rates WHERE facility_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1'.($lock?' FOR UPDATE':''));
        $find->execute([$context['facility']['id'],$window->start->format('Y-m-d H:i:s')]);$rate=$find->fetch();
        if (!$rate) throw new \InvalidArgumentException('A parking rate has not been configured for this facility.');
        foreach (['id','facility_id','initial_minutes','initial_centavos','succeeding_hour_centavos','grace_minutes','additional_centavos','cancellation_cutoff_minutes'] as $key) $rate[$key]=(int)$rate[$key];
        if ($rate['daily_max_centavos']!==null) $rate['daily_max_centavos']=(int)$rate['daily_max_centavos'];
        $estimate=FeeEstimate::calculate($rate,$window->hours*60);
        $snapshot=['version'=>1,'rate'=>$rate,'estimate'=>$estimate,
            'location'=>['facility'=>$context['facility']['name'],'floor'=>$context['floor']['label'],'slot'=>$context['slot']['label']],
            'cancellation_cutoff_minutes'=>$rate['cancellation_cutoff_minutes']];
        return ['rate'=>$rate,'estimate'=>$estimate,'snapshot'=>$snapshot,'quote_hash'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))];
    }

    public function quote(int $slotId, AvailabilityWindow $window): array
    {
        $context=$this->context($slotId);$this->usable($context);
        return $context + $this->price($context,$window) + ['window'=>$window];
    }

    /** Call only while holding facility -> floor -> slot locks. Hold rows precede reservation rows. */
    private function expireSlot(\PDO $pdo, int $slotId): int
    {
        $find=$pdo->prepare('SELECT id,reservation_id FROM reservation_holds WHERE slot_id=? AND released_at IS NULL AND (expires_at<=UTC_TIMESTAMP() OR ends_at<=UTC_TIMESTAMP()) ORDER BY id FOR UPDATE');$find->execute([$slotId]);$expired=$find->fetchAll();$count=0;
        foreach ($expired as $hold) {
            if ($hold['reservation_id']) {
                $update=$pdo->prepare("UPDATE reservations SET status='expired' WHERE id=? AND status='pending'");$update->execute([$hold['reservation_id']]);
                if ($update->rowCount()) { ++$count; Audit::record('reservation.expired',null,(int)$hold['reservation_id'],'reservation'); }
            }
            $pdo->prepare('UPDATE reservation_holds SET released_at=UTC_TIMESTAMP() WHERE id=?')->execute([$hold['id']]);
        }
        return $count;
    }

    private function assertAvailable(\PDO $pdo, int $slotId, AvailabilityWindow $window): void
    {
        $start=$window->start->format('Y-m-d H:i:s');$end=$window->end->format('Y-m-d H:i:s');
        // Locking reads are essential: an earlier idempotency lookup can establish an old MVCC snapshot.
        $find=$pdo->prepare("SELECT id FROM reservations WHERE slot_id=? AND status='confirmed' AND starts_at<? AND ends_at>? LIMIT 1 FOR UPDATE");$find->execute([$slotId,$end,$start]);
        if ($find->fetch()) throw new ReservationConflict('That space has a confirmed reservation for this time. Choose another space.');
        $find=$pdo->prepare('SELECT id FROM reservation_holds WHERE slot_id=? AND released_at IS NULL AND expires_at>UTC_TIMESTAMP() AND starts_at<? AND ends_at>? LIMIT 1 FOR UPDATE');$find->execute([$slotId,$end,$start]);
        if ($find->fetch()) throw new ReservationConflict('Another checkout is holding that space. Choose another space or try after the hold expires.');
        $find=$pdo->prepare("SELECT p.id FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id WHERE r.slot_id=? AND p.status='active' AND p.exit_at IS NULL LIMIT 1 FOR UPDATE");$find->execute([$slotId]);
        if ($find->fetch()) throw new ReservationConflict('That space is currently occupied. Choose another space.');
    }

    public function byRequest(int $userId, string $key): ?int
    {
        $find=Database::connection()->prepare('SELECT id FROM reservations WHERE user_id=? AND request_key=?');$find->execute([$userId,$key]);return ($id=$find->fetchColumn())?(int)$id:null;
    }

    public function create(int $userId, int $slotId, AvailabilityWindow $window, string $key, string $expectedHash, ?int $version = null): int
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new \InvalidArgumentException('This checkout form is invalid. Open a fresh review.');
        try {
            return $this->transaction(function (\PDO $pdo) use ($userId,$slotId,$window,$key,$expectedHash,$version): int {
                $this->driver($pdo,$userId,$version);
                if ($existing=$this->byRequest($userId,$key)) {
                    $find=$pdo->prepare('SELECT slot_id,starts_at,ends_at FROM reservations WHERE id=?');$find->execute([$existing]);$old=$find->fetch();
                    if ((int)$old['slot_id']!==$slotId || $old['starts_at']!==$window->start->format('Y-m-d H:i:s') || $old['ends_at']!==$window->end->format('Y-m-d H:i:s')) throw new ReservationConflict('This checkout key was already used for another selection.');
                    return $existing;
                }
                $context=$this->context($slotId,true);$this->usable($context);
                $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
                if ($window->start<$now->modify('-1 minute') || $window->start>$now->modify('+1 year')) throw new \InvalidArgumentException('Your arrival time has passed. Review a new arrival time.');
                $this->expireSlot($pdo,$slotId);
                $count=$pdo->prepare('SELECT COUNT(*) FROM reservation_holds WHERE user_id=? AND released_at IS NULL AND expires_at>UTC_TIMESTAMP() AND ends_at>UTC_TIMESTAMP()');$count->execute([$userId]);
                if ((int)$count->fetchColumn()>=$this->config['maximum_active_holds_per_driver']) throw new ReservationConflict('You already have '.$this->config['maximum_active_holds_per_driver'].' live checkouts. Cancel one or wait for one to expire.');
                $this->assertAvailable($pdo,$slotId,$window);
                $price=$this->price($context,$window,true);
                if (!hash_equals($price['quote_hash'],$expectedHash)) throw new ReservationConflict('The parking details or rate changed. Review the updated estimate before trying again.');
                $reference='PNG-'.strtoupper(bin2hex(random_bytes(10)));
                $pdo->prepare('INSERT INTO reservations (reference,user_id,slot_id,rate_id,starts_at,ends_at,estimated_centavos,rate_snapshot,request_key) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$reference,$userId,$slotId,$price['rate']['id'],$window->start->format('Y-m-d H:i:s'),$window->end->format('Y-m-d H:i:s'),$price['estimate']['total_centavos'],json_encode($price['snapshot'],JSON_THROW_ON_ERROR),$key]);
                $id=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO reservation_holds (user_id,slot_id,reservation_id,token_hash,starts_at,ends_at,expires_at) VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE))')->execute([$userId,$slotId,$id,hash('sha256',random_bytes(32)),$window->start->format('Y-m-d H:i:s'),$window->end->format('Y-m-d H:i:s'),$this->config['hold_minutes']]);
                Audit::record('reservation.hold_created',$userId,$id,'reservation');return $id;
            });
        } catch (\PDOException $error) {
            if ((int)($error->errorInfo[1]??0)===1062) throw new ReservationConflict('This checkout was already submitted. Open My Reservations to continue.');
            throw $error;
        }
    }

    public function expire(int $limit=100, ?int $userId=null): int
    {
        $sql='SELECT DISTINCT slot_id FROM reservation_holds WHERE released_at IS NULL AND (expires_at<=UTC_TIMESTAMP() OR ends_at<=UTC_TIMESTAMP())'.($userId?' AND user_id=?':'').' ORDER BY slot_id LIMIT '.max(1,min(500,$limit));
        $find=Database::connection()->prepare($sql);$find->execute($userId?[$userId]:[]);$ids=$find->fetchAll(\PDO::FETCH_COLUMN);$count=0;
        foreach ($ids as $slotId) $count+=$this->transaction(function (\PDO $pdo) use ($slotId): int { $this->context((int)$slotId,true);return $this->expireSlot($pdo,(int)$slotId); });
        return $count;
    }

    public function detail(int $userId, int $id): array
    {
        $this->expire(100,$userId);
        $find=Database::connection()->prepare('SELECT r.*,f.name AS facility_name,f.address,fl.label AS floor_label,s.label AS slot_label,h.expires_at,h.released_at,UTC_TIMESTAMP() AS server_now FROM reservations r JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id LEFT JOIN reservation_holds h ON h.reservation_id=r.id WHERE r.id=? AND r.user_id=?');$find->execute([$id,$userId]);
        $row=$find->fetch();if (!$row) throw new \OutOfBoundsException('Reservation not found.');
        return $row;
    }

    public function history(int $userId, int $page=1): array
    {
        $this->expire(100,$userId);
        $find=Database::connection()->prepare('SELECT r.id,r.reference,r.starts_at,r.ends_at,r.status,r.estimated_centavos,f.name AS facility_name,s.label AS slot_label FROM reservations r JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id WHERE r.user_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT 21 OFFSET '.(($page-1)*20));$find->execute([$userId]);return $find->fetchAll();
    }

    public function cancel(int $userId, int $id, ?int $version=null): void
    {
        $find=Database::connection()->prepare('SELECT slot_id FROM reservations WHERE id=? AND user_id=?');$find->execute([$id,$userId]);$slotId=$find->fetchColumn();if (!$slotId) throw new \OutOfBoundsException('Reservation not found.');
        $this->transaction(function (\PDO $pdo) use ($userId,$id,$slotId,$version): void {
            $this->driver($pdo,$userId,$version);$this->context((int)$slotId,true);$this->expireSlot($pdo,(int)$slotId);
            $find=$pdo->prepare('SELECT id FROM reservation_holds WHERE reservation_id=? FOR UPDATE');$find->execute([$id]);$find->fetchAll();
            $find=$pdo->prepare('SELECT * FROM reservations WHERE id=? AND user_id=? FOR UPDATE');$find->execute([$id,$userId]);$row=$find->fetch();
            if ($row['status']==='cancelled') return;
            if (!in_array($row['status'],['pending','confirmed'],true)) throw new ReservationConflict('Only pending or eligible confirmed reservations can be cancelled.');
            $find=$pdo->prepare("SELECT id FROM parking_sessions WHERE reservation_id=? AND status='active' FOR UPDATE");$find->execute([$id]);if ($find->fetch()) throw new ReservationConflict('A parking session is active. This reservation cannot be cancelled.');
            $find=$pdo->prepare("SELECT id FROM payment_transactions WHERE reservation_id=? AND status='paid' FOR UPDATE");$find->execute([$id]);if ($find->fetch()) throw new ReservationConflict('Paid reservations need a verified refund workflow. Please contact the facility.');
            $find=$pdo->prepare("SELECT local_status FROM payment_attempts WHERE reservation_id=? AND payment_type IN ('INITIAL_RESERVATION','PARKING_EXTENSION','OVERTIME_SETTLEMENT') AND local_status IN ('created','checkout_created','pending_verification','verified','reconciliation') ORDER BY id DESC FOR UPDATE");$find->execute([$id]);
            foreach ($find->fetchAll(\PDO::FETCH_COLUMN) as $paymentStatus) {
                if (in_array($paymentStatus, ['verified','reconciliation'], true)) throw new ReservationConflict('This reservation has a verified or reconciling payment and cannot be cancelled without an approved refund workflow.');
                throw new ReservationConflict('Payment verification is still in progress. The reservation hold cannot be released until payment reaches a final state.');
            }
            if ($row['status']==='confirmed') {
                $snapshot=json_decode($row['rate_snapshot'],true,64,JSON_THROW_ON_ERROR);
                if (!isset($snapshot['cancellation_cutoff_minutes'])) throw new ReservationConflict('Cancellation policy is unavailable for this older reservation. Contact the facility.');
                $cutoff=(new \DateTimeImmutable($row['starts_at'],new \DateTimeZone('UTC')))->modify('-'.(int)$snapshot['cancellation_cutoff_minutes'].' minutes');
                if (new \DateTimeImmutable('now',new \DateTimeZone('UTC'))>$cutoff) throw new ReservationConflict('The cancellation deadline has passed.');
            }
            $pdo->prepare("UPDATE reservations SET status='cancelled',cancelled_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
            $pdo->prepare('UPDATE reservation_holds SET released_at=COALESCE(released_at,UTC_TIMESTAMP()) WHERE reservation_id=?')->execute([$id]);
            $pdo->prepare('UPDATE qr_tickets SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE reservation_id=?')->execute([$id]);
            Audit::record('reservation.cancelled',$userId,$id,'reservation');
        });
    }
}
