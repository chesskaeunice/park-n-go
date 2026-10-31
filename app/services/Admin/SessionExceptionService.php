<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\{Clock, Database};
use ParkNGo\Services\Parking\OvertimeService;

/**
 * Permissioned closure of an active parking session that cannot finish through the kiosk. It is not a refund
 * and not a waiver: payment records are untouched, and any overtime accrued at closure is recorded as an
 * unsettled obligation that stays in the reconciliation queue.
 */
final class SessionExceptionService
{
    public const CATEGORIES = [
        'data_inconsistency' => 'Confirmed system data inconsistency',
        'emergency_closure' => 'Emergency operational closure',
        'kiosk_unavailable' => 'Legitimate session cannot complete at the kiosk',
    ];

    public function session(int $sessionId, ?\PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $find = $pdo->prepare('SELECT p.*,r.reference,r.user_id,r.slot_id,s.label AS slot_label,fl.facility_id,f.name AS facility_name,pr.overtime_hour_centavos,u.name AS driver_name
            FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id
            JOIN parking_facilities f ON f.id=fl.facility_id JOIN parking_rates pr ON pr.id=r.rate_id JOIN users u ON u.id=r.user_id WHERE p.id=?');
        $find->execute([$sessionId]); $session = $find->fetch();
        if (!$session) throw new \OutOfBoundsException('Parking session not found.');
        $session['overtime'] = (new OvertimeService())->calculate($session, Clock::now()->format('Y-m-d H:i:s'), $pdo);
        $pending = $pdo->prepare('SELECT reference,payment_type,amount_centavos,local_status FROM payment_attempts WHERE parking_session_id=? AND local_status IN ' . OperationsMonitor::PENDING_ATTEMPT . ' ORDER BY id');
        $pending->execute([$sessionId]); $session['pending_payments'] = $pending->fetchAll();
        return $session;
    }

    /** Validates the request and returns what confirming would record. Changes nothing. */
    public function preview(int $adminId, int $sessionId, int $facilityId, string $category, string $reason): array
    {
        if (!AdminPermissions::has($adminId, 'active_session_exception')) throw new PermissionDenied('active_session_exception');
        if (!isset(self::CATEGORIES[$category])) throw new \InvalidArgumentException('Choose why this session needs an exception.');
        $reason = Reason::require($reason, 20);
        $session = $this->session($sessionId);
        $this->assertClosable($session, $facilityId);
        return ['session' => $session, 'category' => $category, 'reason' => $reason];
    }

    public function close(int $adminId, int $sessionId, int $facilityId, string $category, string $reason): array
    {
        if (!isset(self::CATEGORIES[$category])) throw new \InvalidArgumentException('Choose why this session needs an exception.');
        $reason = Reason::require($reason, 20);
        return Database::transaction(function (\PDO $pdo) use ($adminId, $sessionId, $facilityId, $category, $reason): array {
            AdminPermissions::authorize($pdo, $adminId, 'active_session_exception');
            // Same lock order as kiosk exit and payment fulfilment: the parking session row first. The locking read
            // precedes the first plain read, so the session details below come from a snapshot taken after the lock.
            $pdo->prepare('SELECT id FROM parking_sessions WHERE id=? FOR UPDATE')->execute([$sessionId]);
            $session = $this->session($sessionId, $pdo);
            $this->assertClosable($session, $facilityId);
            $now = Clock::now()->format('Y-m-d H:i:s'); $overtime = $session['overtime'];
            $unsettled = $overtime['payment_required'];
            $update = $pdo->prepare("UPDATE parking_sessions SET status='completed',exit_at=?,exited_by=? WHERE id=? AND status='active' AND exit_at IS NULL");
            $update->execute([$now, $adminId, $sessionId]);
            if ($update->rowCount() !== 1) throw new \InvalidArgumentException('This parking session has already been completed.');
            $pdo->prepare("UPDATE reservations SET status='completed' WHERE id=? AND status='confirmed'")->execute([$session['reservation_id']]);
            $pdo->prepare('UPDATE reservation_holds SET released_at=COALESCE(released_at,UTC_TIMESTAMP()) WHERE reservation_id=?')->execute([$session['reservation_id']]);
            $pdo->prepare("UPDATE qr_tickets SET status='completed',revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE reservation_id=?")->execute([$session['reservation_id']]);
            $pdo->prepare('INSERT INTO parking_session_exceptions (parking_session_id,facility_id,category,reason,closed_by,closed_at,paid_until_at_close,chargeable_from,outstanding_seconds,outstanding_rate_centavos,outstanding_centavos,obligation_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$sessionId, $session['facility_id'], $category, $reason, $adminId, $now, $session['paid_until'], $overtime['chargeable_from'], $unsettled ? $overtime['elapsed_seconds'] : 0,
                    $unsettled ? $overtime['rate_centavos'] : null, $unsettled ? $overtime['estimated_centavos'] : null, $unsettled ? 'unsettled' : 'none']);
            $exceptionId = (int) $pdo->lastInsertId();
            AdminAudit::record($pdo, $adminId, 'session.exception_closed', 'parking_session', $sessionId, (int) $session['facility_id'], $reason,
                ['status' => 'active', 'paid_until' => $session['paid_until'], 'reservation' => $session['reference'], 'pending_payments' => array_column($session['pending_payments'], 'reference')],
                ['status' => 'completed', 'exit_at' => $now, 'category' => $category, 'exception_id' => $exceptionId, 'obligation_status' => $unsettled ? 'unsettled' : 'none',
                    'outstanding_seconds' => $unsettled ? $overtime['elapsed_seconds'] : 0, 'outstanding_centavos' => $unsettled ? $overtime['estimated_centavos'] : null]);
            return ['exception_id' => $exceptionId, 'unsettled' => $unsettled, 'outstanding_centavos' => $unsettled ? $overtime['estimated_centavos'] : null, 'session' => $session];
        });
    }

    private function assertClosable(array $session, int $facilityId): void
    {
        if ((int) $session['facility_id'] !== $facilityId) throw new \InvalidArgumentException('This session belongs to another facility. Check the facility before continuing.');
        if ($session['status'] !== 'active' || $session['exit_at'] !== null) throw new \InvalidArgumentException('This parking session is no longer active.');
    }

    public function recent(int $facilityId, int $limit = 20): array
    {
        $find = Database::connection()->prepare('SELECT e.*,f.name AS facility_name,u.name AS closed_by_name,r.reference FROM parking_session_exceptions e JOIN parking_facilities f ON f.id=e.facility_id JOIN users u ON u.id=e.closed_by
            JOIN parking_sessions p ON p.id=e.parking_session_id JOIN reservations r ON r.id=p.reservation_id WHERE (?=0 OR e.facility_id=?) ORDER BY e.id DESC LIMIT ' . max(1, min(100, $limit)));
        $find->execute([$facilityId, $facilityId]);
        return $find->fetchAll();
    }
}
