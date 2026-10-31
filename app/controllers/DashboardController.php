<?php
declare(strict_types=1);
namespace ParkNGo\Controllers;

use ParkNGo\Models\ParkingInventory;
use ParkNGo\Services\{Auth, Database};
use ParkNGo\Services\Parking\OvertimeService;
use ParkNGo\Services\Parking\ReservationService;

/**
 * Read-only data for the mobile driver home screen: a few active facilities and the driver's next
 * pending/confirmed reservation. It reuses existing queries and never creates holds or payments.
 */
final class DashboardController
{
    public function handle(string $page): array
    {
        $user = Auth::user();
        $facilities = array_slice((new ParkingInventory())->facilities(''), 0, 6);
        $upcoming = null;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ((new ReservationService())->history((int) $user['id'], 1) as $row) {
            if (!in_array($row['status'], ['pending', 'confirmed'], true)) continue;
            if (new \DateTimeImmutable($row['ends_at'], new \DateTimeZone('UTC')) <= $now) continue;
            if ($upcoming === null || $row['starts_at'] < $upcoming['starts_at']) $upcoming = $row;
        }
        $paymentError = is_string($_SESSION['session_payment_error'] ?? null) ? $_SESSION['session_payment_error'] : null;
        unset($_SESSION['session_payment_error']);
        $find = Database::connection()->prepare("SELECT p.id,p.entry_at,p.paid_until,p.status,f.name AS facility_name,s.label AS slot_label,pr.overtime_hour_centavos,pr.succeeding_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id JOIN parking_rates pr ON pr.id=r.rate_id WHERE r.user_id=? AND p.status='active' AND p.exit_at IS NULL ORDER BY p.id DESC LIMIT 1");
        $find->execute([(int)$user['id']]); $activeSession=$find->fetch() ?: null;
        $overtime=$activeSession ? (new OvertimeService())->calculate($activeSession, null, Database::connection()) : null;
        return compact('facilities', 'upcoming', 'activeSession', 'overtime', 'paymentError');
    }
}
