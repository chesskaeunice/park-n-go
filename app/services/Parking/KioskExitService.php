<?php
declare(strict_types=1);

namespace ParkNGo\Services\Parking;

use ParkNGo\Services\{Database, KioskAuth, RateLimiter};
use ParkNGo\Services\Qr\TicketService;

final class KioskExitService
{
    public function preview(array $kiosk, string $submitted): array
    {
        $current = KioskAuth::current();
        if (!$current || (int) $current['id'] !== (int) $kiosk['id']) throw new \InvalidArgumentException('This kiosk is no longer authorized.');
        $ticket = (new TicketService())->resolve($submitted, true);
        if ((int) $ticket['facility_id'] !== (int) $kiosk['facility_id']) throw new \InvalidArgumentException('This ticket belongs to another facility.');
        $pdo = Database::connection();
        $payment = $pdo->prepare("SELECT 1 FROM payment_attempts WHERE reservation_id=? AND payment_type='INITIAL_RESERVATION' AND local_status='verified' LIMIT 1");
        $payment->execute([$ticket['reservation_id']]);
        if (!$payment->fetchColumn()) throw new \InvalidArgumentException('This reservation does not have verified payment.');
        $find = $pdo->prepare('SELECT p.id, p.entry_at, p.paid_until, p.status, p.exit_at, s.label AS slot_label, f.id AS facility_id, f.name AS facility_name, r.reference, pr.overtime_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE p.reservation_id=? LIMIT 1');
        $find->execute([$ticket['reservation_id']]); $session = $find->fetch();
        if (!$session || $session['status'] !== 'active' || $session['exit_at'] !== null) throw new \InvalidArgumentException('No active parking session was found for this ticket.');
        $overtime = (new OvertimeService())->calculate($session, null, $pdo);
        if ($overtime['payment_required']) throw new \InvalidArgumentException($overtime['rate_centavos'] === null ? 'This session is overdue. Overtime settlement is not available yet.' : 'This session has unpaid overtime. Exit is blocked.');
        $_SESSION['kiosk_pending_exit'] = ['ticket_id' => (int) $ticket['id'], 'kiosk_id' => (int) $kiosk['id'], 'verified_at' => time()];
        return ['facility_name' => $session['facility_name'], 'slot_label' => $session['slot_label'], 'reference' => $session['reference'], 'entry_at' => $session['entry_at'], 'paid_until' => $overtime['paid_until'], 'status' => 'ready'];
    }

    public function confirm(array $kiosk): array
    {
        $pending = $_SESSION['kiosk_pending_exit'] ?? null;
        if (!is_array($pending) || (int) ($pending['kiosk_id'] ?? 0) !== (int) $kiosk['id'] || time() - (int) ($pending['verified_at'] ?? 0) > 300) {
            unset($_SESSION['kiosk_pending_exit']);
            throw new \InvalidArgumentException('Verify an active ticket again before confirming exit.');
        }
        $result = (new ParkingSessionService())->exitForKiosk((int) $pending['ticket_id'], (int) $kiosk['id'], (int) $kiosk['facility_id']);
        unset($_SESSION['kiosk_pending_exit']);
        return ['status' => 'completed', 'facility_name' => $kiosk['facility_name'], 'session' => $result['session']];
    }

    public static function allowed(string $scope, string $identifier): bool
    {
        return RateLimiter::consume($scope, $identifier, 30, 900);
    }
}
