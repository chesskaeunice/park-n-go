<?php
declare(strict_types=1);
namespace ParkNGo\Services\Parking;

use ParkNGo\Services\{Audit, Database, KioskAuth};
use ParkNGo\Services\Qr\TicketService;

final class ParkingSessionService
{
    private function facilityAllowed(\PDO $pdo, int $attendantId, int $facilityId): bool
    {
        $check = $pdo->prepare('SELECT 1 FROM attendant_facilities WHERE attendant_id=? AND facility_id=?'); $check->execute([$attendantId, $facilityId]);
        return (bool) $check->fetchColumn();
    }

    public function entry(string $submitted, int $attendantId): array
    {
        return Database::transaction(function (\PDO $pdo) use ($submitted, $attendantId): array {
            $ticket = (new TicketService())->resolve($submitted);
            $find = $pdo->prepare('SELECT r.*,s.id AS slot_id,f.id AS facility_id,pr.overtime_hour_centavos FROM reservations r JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE r.id=? FOR UPDATE'); $find->execute([$ticket['reservation_id']]); $reservation = $find->fetch();
            if (!$reservation || !$this->facilityAllowed($pdo, $attendantId, (int) $reservation['facility_id'])) throw new \InvalidArgumentException('This attendant is not authorized for the ticket facility.');
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); $start = new \DateTimeImmutable($reservation['starts_at'], new \DateTimeZone('UTC'));
            if ($now > $start->modify('+30 minutes')) {
                $pdo->prepare("UPDATE reservations SET status='no_show',no_show_at=COALESCE(no_show_at,UTC_TIMESTAMP()) WHERE id=? AND status='confirmed'")->execute([$reservation['id']]);
                $pdo->prepare("UPDATE qr_tickets SET status='revoked',revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE reservation_id=?")->execute([$reservation['id']]);
                throw new \InvalidArgumentException('The entry window has closed; this reservation is now a no-show.');
            }
            if ($now < $start->modify('-5 minutes')) throw new \InvalidArgumentException('Entry is not available yet.');
            $existing = $pdo->prepare('SELECT id FROM parking_sessions WHERE reservation_id=? FOR UPDATE'); $existing->execute([$reservation['id']]);
            if ($existing->fetch()) throw new \InvalidArgumentException('Duplicate entry scan: this reservation already has a parking session.');
            $slot = $pdo->prepare('SELECT status FROM parking_slots WHERE id=? FOR UPDATE'); $slot->execute([$reservation['slot_id']]);
            if ($slot->fetchColumn() !== 'available') throw new \InvalidArgumentException('The assigned parking slot is not available.');
            $occupied = $pdo->prepare("SELECT p.id FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id WHERE r.slot_id=? AND p.status='active' AND p.exit_at IS NULL LIMIT 1 FOR UPDATE"); $occupied->execute([$reservation['slot_id']]);
            if ($occupied->fetch()) throw new \InvalidArgumentException('The assigned parking slot is currently occupied.');
            $minutes = max(1, (int) round((strtotime($reservation['ends_at']) - strtotime($reservation['starts_at'])) / 60));
            $paidUntil = $now->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO parking_sessions (reservation_id,entered_by,entry_at,purchased_minutes,paid_until,status) VALUES (?,?,UTC_TIMESTAMP(),?,?, 'active')")->execute([$reservation['id'], $attendantId, $minutes, $paidUntil]);
            $pdo->prepare("INSERT INTO qr_scan_logs (ticket_id,attendant_id,direction,result,message) VALUES (?,?, 'entry','accepted',?)")->execute([$ticket['id'], $attendantId, 'Entry accepted.']);
            Audit::record('parking.entry_accepted', $attendantId, (int) $reservation['id'], 'reservation');
            return ['status' => 'accepted', 'reservation' => $reservation, 'paid_until' => $paidUntil];
        });
    }

    public function exit(string $submitted, int $attendantId): array
    {
        return Database::transaction(function (\PDO $pdo) use ($submitted, $attendantId): array {
            $ticket = (new TicketService())->resolve($submitted, true);
            return $this->finalizeExit($pdo, $ticket, (int) $ticket['facility_id'], $attendantId, null, false);
        });
    }

    public function exitForKiosk(int $ticketId, int $kioskId, int $facilityId): array
    {
        return Database::transaction(function (\PDO $pdo) use ($ticketId, $kioskId, $facilityId): array {
            $kiosk = KioskAuth::transactionCurrent($pdo);
            if (!$kiosk || (int) $kiosk['id'] !== $kioskId || (int) $kiosk['facility_id'] !== $facilityId) throw new \InvalidArgumentException('This kiosk is no longer authorized.');
            $ticket = (new TicketService())->resolveTicketId($ticketId, true);
            if ((int) $ticket['facility_id'] !== $facilityId) throw new \InvalidArgumentException('This ticket belongs to another facility.');
            return $this->finalizeExit($pdo, $ticket, $facilityId, null, $kioskId, true);
        });
    }

    private function finalizeExit(\PDO $pdo, array $ticket, int $facilityId, ?int $attendantId, ?int $kioskId, bool $isKiosk): array
    {
        $payment = $pdo->prepare("SELECT 1 FROM payment_attempts WHERE reservation_id=? AND payment_type='INITIAL_RESERVATION' AND local_status='verified' LIMIT 1");
        $payment->execute([$ticket['reservation_id']]);
        if (!$payment->fetchColumn()) throw new \InvalidArgumentException('This reservation does not have verified payment.');
        $find = $pdo->prepare('SELECT p.*,r.slot_id,f.id AS facility_id,pr.overtime_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE p.reservation_id=? FOR UPDATE');
        $find->execute([$ticket['reservation_id']]); $session = $find->fetch();
        if (!$session || $session['status'] !== 'active' || $session['exit_at'] !== null) throw new \InvalidArgumentException('No active parking session was found for this ticket.');
        if ((int) $session['facility_id'] !== $facilityId) throw new \InvalidArgumentException('This ticket belongs to another facility.');
        if (!$isKiosk && (!$attendantId || !$this->facilityAllowed($pdo, $attendantId, $facilityId))) throw new \InvalidArgumentException('This attendant is not authorized for the ticket facility.');
        $overtime = (new OvertimeService())->calculate($session, null, $pdo);
        if ($overtime['payment_required']) throw new \InvalidArgumentException($overtime['rate_centavos'] === null ? 'Payment Required / Overtime feature pending.' : 'Payment Required: verified overtime settlement is required before exit.');
        $update = $pdo->prepare("UPDATE parking_sessions SET status='completed',exit_at=UTC_TIMESTAMP(),exited_by=? WHERE id=? AND status='active' AND exit_at IS NULL");
        $update->execute([$attendantId, $session['id']]);
        if ($update->rowCount() !== 1) throw new \InvalidArgumentException('This parking session has already been completed.');
        $pdo->prepare("UPDATE reservations SET status='completed' WHERE id=? AND status='confirmed'")->execute([$session['reservation_id']]);
        // A short stay can end before the original checkout hold lapses; exit releases the slot fully.
        $pdo->prepare('UPDATE reservation_holds SET released_at=COALESCE(released_at,UTC_TIMESTAMP()) WHERE reservation_id=?')->execute([$session['reservation_id']]);
        $pdo->prepare("UPDATE qr_tickets SET status='completed',revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE reservation_id=?")->execute([$session['reservation_id']]);
        if ($attendantId) $pdo->prepare("INSERT INTO qr_scan_logs (ticket_id,attendant_id,direction,result,message) VALUES (?,?, 'exit','accepted',?)")->execute([$ticket['id'], $attendantId, 'Exit accepted.']);
        Audit::record($isKiosk ? 'parking.kiosk_exit_accepted' : 'parking.exit_accepted', $attendantId, (int) ($isKiosk ? $kioskId : $session['reservation_id']), $isKiosk ? 'exit_kiosk' : 'reservation');
        return ['status' => 'accepted', 'session' => $session, 'facility_id' => $facilityId];
    }
}
