<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\{Clock, Database};
use ParkNGo\Services\Parking\OvertimeService;

/**
 * Admin facility monitoring. Slot occupancy is always derived from authoritative parking sessions,
 * confirmed reservations, and live holds, using the same precedence as public availability:
 * out of service > occupied > reserved > held > available. Inventory status alone never makes a slot free.
 */
final class OperationsMonitor
{
    public const PENDING_ATTEMPT = "('created','checkout_created','pending_verification')";
    /** Joins any payment attempt `pa` to its facility through the reservation or the parking session. */
    public const ATTEMPT_FACILITY = 'LEFT JOIN parking_sessions aps ON aps.id=pa.parking_session_id JOIN reservations ar ON ar.id=COALESCE(pa.reservation_id,aps.reservation_id) JOIN parking_slots ass ON ass.id=ar.slot_id JOIN parking_floors afl ON afl.id=ass.floor_id JOIN parking_facilities af ON af.id=afl.facility_id';

    public function facilities(): array
    {
        return Database::connection()->query('SELECT id,name,is_active FROM parking_facilities ORDER BY name,id')->fetchAll();
    }

    /** Every slot in scope with its derived state, protections, and change fingerprint. Batched queries, no per-slot lookups. */
    public function slots(int $facilityId, ?\DateTimeImmutable $now = null): array
    {
        $pdo = Database::connection(); $now ??= Clock::now(); $at = $now->format('Y-m-d H:i:s');
        $scope = ' AND (?=0 OR fl.facility_id=?)';
        $find = $pdo->prepare("SELECT s.id,s.label,s.status AS inventory_status,s.floor_id,fl.label AS floor_label,fl.is_active AS floor_active,f.id AS facility_id,f.name AS facility_name,f.is_active AS facility_active
            FROM parking_slots s JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id WHERE 1=1{$scope} ORDER BY f.name,f.id,fl.sort_order,fl.id,s.position_y,s.position_x,s.id");
        $find->execute([$facilityId, $facilityId]);
        $slots = [];
        foreach ($find->fetchAll() as $slot) $slots[(int) $slot['id']] = $slot + ['sessions' => [], 'holds' => [], 'confirmed' => [], 'last_event' => null];
        if (!$slots) return [];
        // The facility scope is always the first two placeholders of each query.
        $rows = static function (string $sql, array $extra = []) use ($pdo, $facilityId): array { $statement = $pdo->prepare($sql); $statement->execute([$facilityId, $facilityId, ...$extra]); return $statement->fetchAll(); };
        foreach ($rows("SELECT r.slot_id,ps.id,ps.entry_at,ps.paid_until,r.reference,r.id AS reservation_id,pr.overtime_hour_centavos,
                (SELECT MAX(o.grace_until) FROM parking_overtime_settlements o WHERE o.parking_session_id=ps.id AND o.status='verified') AS grace_until
            FROM parking_sessions ps JOIN reservations r ON r.id=ps.reservation_id JOIN parking_rates pr ON pr.id=r.rate_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id
            WHERE ps.status='active' AND ps.exit_at IS NULL{$scope} ORDER BY ps.id") as $session) {
            $session['overtime'] = (new OvertimeService())->calculate($session, $at);
            $slots[(int) $session['slot_id']]['sessions'][] = $session;
        }
        foreach ($rows("SELECT h.slot_id,h.id,h.starts_at,h.ends_at,h.expires_at,h.reservation_id,r.reference,r.status AS reservation_status
            FROM reservation_holds h LEFT JOIN reservations r ON r.id=h.reservation_id JOIN parking_slots s ON s.id=h.slot_id JOIN parking_floors fl ON fl.id=s.floor_id
            WHERE h.released_at IS NULL{$scope} AND h.expires_at>? AND h.ends_at>? ORDER BY h.id", [$at, $at]) as $hold) $slots[(int) $hold['slot_id']]['holds'][] = $hold;
        foreach ($rows("SELECT r.slot_id,r.id,r.reference,r.starts_at,r.ends_at FROM reservations r JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id
            WHERE r.status='confirmed'{$scope} AND r.ends_at>? AND NOT EXISTS (SELECT 1 FROM parking_sessions x WHERE x.reservation_id=r.id) ORDER BY r.starts_at,r.id", [$at]) as $reservation) $slots[(int) $reservation['slot_id']]['confirmed'][] = $reservation;
        foreach ($rows("SELECT r.slot_id,MAX(ps.entry_at) AS last_entry,MAX(ps.exit_at) AS last_exit FROM parking_sessions ps JOIN reservations r ON r.id=ps.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id WHERE 1=1{$scope} GROUP BY r.slot_id") as $event) {
            $slots[(int) $event['slot_id']]['last_event'] = $event['last_exit'] !== null && $event['last_exit'] >= $event['last_entry'] ? ['Exit', $event['last_exit']] : ['Entry', $event['last_entry']];
        }
        foreach ($rows("SELECT a.target_id AS slot_id,MAX(a.created_at) AS at FROM admin_audit_events a JOIN parking_slots s ON s.id=a.target_id JOIN parking_floors fl ON fl.id=s.floor_id WHERE a.target_type='parking_slot' AND a.outcome='succeeded'{$scope} GROUP BY a.target_id") as $event) {
            $slot = &$slots[(int) $event['slot_id']];
            if ($slot['last_event'] === null || $event['at'] > $slot['last_event'][1]) $slot['last_event'] = ['Admin correction', $event['at']];
            unset($slot);
        }
        foreach ($slots as &$slot) $slot += self::derive($slot, $at);
        unset($slot);
        return array_values($slots);
    }

    private static function derive(array $slot, string $at): array
    {
        $covers = static fn (array $row): bool => $row['starts_at'] <= $at && $row['ends_at'] > $at;
        $state = match (true) {
            $slot['inventory_status'] !== 'available' || !$slot['floor_active'] || !$slot['facility_active'] => $slot['inventory_status'] === 'maintenance' ? 'maintenance' : 'disabled',
            $slot['sessions'] !== [] => 'occupied',
            array_filter($slot['confirmed'], $covers) !== [] => 'reserved',
            array_filter($slot['holds'], $covers) !== [] => 'held',
            default => 'available',
        };
        // Stale holds are live hold rows whose reservation is no longer pending or confirmed; they protect nothing.
        $stale = array_values(array_filter($slot['holds'], static fn (array $hold): bool => !in_array($hold['reservation_status'], ['pending', 'confirmed'], true)));
        return ['state' => $state, 'stale_holds' => $stale, 'overdue' => array_filter($slot['sessions'], static fn (array $s): bool => $s['overtime']['payment_required']) !== [],
            'fingerprint' => self::fingerprint($slot)];
    }

    /** Changes whenever the authoritative records an admin reviewed change. */
    private static function fingerprint(array $slot): string
    {
        $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
        return hash('sha256', json_encode([$slot['inventory_status'], $ids($slot['sessions']), $ids($slot['holds']), $ids($slot['confirmed'])], JSON_THROW_ON_ERROR));
    }

    public function metrics(int $facilityId, ?\DateTimeImmutable $now = null): array
    {
        $pdo = Database::connection(); $now ??= Clock::now(); $at = $now->format('Y-m-d H:i:s');
        $slots = $this->slots($facilityId, $now);
        $count = static fn (string $state): int => count(array_filter($slots, static fn (array $slot): bool => $slot['state'] === $state));
        $value = static function (string $sql, array $params) use ($pdo): int { $find = $pdo->prepare($sql); $find->execute($params); return (int) $find->fetchColumn(); };
        $facilities = $value('SELECT COUNT(*) FROM parking_facilities WHERE (?=0 OR id=?)', [$facilityId, $facilityId]);
        $sessions = array_merge(...array_column($slots, 'sessions') ?: [[]]);
        return [
            'facilities' => $facilities,
            'active_facilities' => $value('SELECT COUNT(*) FROM parking_facilities WHERE is_active=1 AND (?=0 OR id=?)', [$facilityId, $facilityId]),
            'slots' => count($slots),
            'available' => $count('available'), 'occupied' => $count('occupied'), 'reserved' => $count('reserved'), 'held' => $count('held'),
            'out_of_service' => $count('maintenance') + $count('disabled'),
            'unpaid_holds' => $value("SELECT COUNT(*) FROM reservation_holds h JOIN reservations r ON r.id=h.reservation_id JOIN parking_slots s ON s.id=h.slot_id JOIN parking_floors fl ON fl.id=s.floor_id WHERE h.released_at IS NULL AND h.expires_at>? AND h.ends_at>? AND r.status='pending' AND (?=0 OR fl.facility_id=?)", [$at, $at, $facilityId, $facilityId]),
            'upcoming_confirmed' => $value("SELECT COUNT(*) FROM reservations r JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id WHERE r.status='confirmed' AND r.starts_at>? AND NOT EXISTS (SELECT 1 FROM parking_sessions x WHERE x.reservation_id=r.id) AND (?=0 OR fl.facility_id=?)", [$at, $facilityId, $facilityId]),
            'active_sessions' => count($sessions),
            'overdue_sessions' => count(array_filter($sessions, static fn (array $s): bool => $s['overtime']['payment_required'])),
            'pending_payments' => $value('SELECT COUNT(*) FROM payment_attempts pa ' . self::ATTEMPT_FACILITY . ' WHERE pa.local_status IN ' . self::PENDING_ATTEMPT . ' AND (?=0 OR af.id=?)', [$facilityId, $facilityId]),
            'reconciliation_open' => count((new ReconciliationService())->cases($facilityId, false, $now)),
            'refunds_awaiting' => $value("SELECT COUNT(*) FROM refund_requests rr JOIN payment_attempts pa ON pa.id=rr.payment_attempt_id " . self::ATTEMPT_FACILITY . " WHERE rr.status IN ('requested','under_review') AND (?=0 OR af.id=?)", [$facilityId, $facilityId]),
            'checked_at' => $at,
        ];
    }

    /**
     * Safe occupancy correction. Locks facility -> floor -> slot (the inventory lock order), re-derives the
     * slot from locking reads, and refuses if anything changed since the admin reviewed it.
     */
    public function correct(int $adminId, int $slotId, string $action, string $expected, string $reason): array
    {
        if (!in_array($action, ['release_stale_holds', 'mark_maintenance', 'mark_disabled', 'mark_available'], true)) throw new \InvalidArgumentException('Choose a supported occupancy correction.');
        $reason = Reason::require($reason);
        // The re-derivation below uses plain reads; READ COMMITTED makes them see what was committed before our locks were granted.
        Database::connection()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return Database::transaction(function (\PDO $pdo) use ($adminId, $slotId, $action, $expected, $reason): array {
            $find = $pdo->prepare('SELECT fl.facility_id,s.floor_id FROM parking_slots s JOIN parking_floors fl ON fl.id=s.floor_id WHERE s.id=?'); $find->execute([$slotId]); $parent = $find->fetch();
            if (!$parent) throw new \OutOfBoundsException('Slot not found.');
            $pdo->prepare('SELECT id FROM parking_facilities WHERE id=? FOR UPDATE')->execute([$parent['facility_id']]);
            $pdo->prepare('SELECT id FROM parking_floors WHERE id=? FOR UPDATE')->execute([$parent['floor_id']]);
            $pdo->prepare('SELECT id FROM parking_slots WHERE id=? FOR UPDATE')->execute([$slotId]);
            foreach (["SELECT h.id FROM reservation_holds h WHERE h.slot_id=? FOR UPDATE", "SELECT r.id FROM reservations r WHERE r.slot_id=? AND r.status IN ('pending','confirmed') FOR UPDATE",
                "SELECT ps.id FROM parking_sessions ps JOIN reservations r ON r.id=ps.reservation_id WHERE r.slot_id=? AND ps.status='active' FOR UPDATE"] as $sql) $pdo->prepare($sql)->execute([$slotId]);
            $before = null;
            foreach ($this->slots((int) $parent['facility_id']) as $slot) if ((int) $slot['id'] === $slotId) $before = $slot;
            if (!$before || !hash_equals($before['fingerprint'], $expected)) throw new \InvalidArgumentException('This slot changed since you reviewed it. Review the current records before correcting it.');
            $summary = static fn (array $slot): array => ['state' => $slot['state'], 'inventory_status' => $slot['inventory_status'], 'active_session_ids' => array_map('intval', array_column($slot['sessions'], 'id')),
                'live_hold_ids' => array_map('intval', array_column($slot['holds'], 'id')), 'confirmed_reservation_ids' => array_map('intval', array_column($slot['confirmed'], 'id'))];
            if ($action === 'release_stale_holds') {
                if (!$before['stale_holds']) throw new \InvalidArgumentException('This slot has no stale holds. Holds for pending or confirmed reservations still protect a driver and cannot be released here.');
                $release = $pdo->prepare('UPDATE reservation_holds SET released_at=UTC_TIMESTAMP() WHERE id=? AND released_at IS NULL');
                foreach ($before['stale_holds'] as $hold) $release->execute([(int) $hold['id']]);
            } else {
                $status = substr($action, 5);
                if ($status === $before['inventory_status']) throw new \InvalidArgumentException('The slot is already marked ' . $status . '.');
                if ($before['sessions']) throw new \InvalidArgumentException('An active parking session occupies this slot. Close it through the kiosk or the permissioned active-session exception workflow first.');
                $protected = array_filter($before['holds'], static fn (array $hold): bool => in_array($hold['reservation_status'], ['pending', 'confirmed'], true));
                if ($status !== 'available' && ($before['confirmed'] || $protected)) throw new \InvalidArgumentException('Confirmed reservations or live checkouts depend on this slot. Resolve them before taking it out of service.');
                $pdo->prepare('UPDATE parking_slots SET status=? WHERE id=?')->execute([$status, $slotId]);
            }
            $after = null;
            foreach ($this->slots((int) $parent['facility_id']) as $slot) if ((int) $slot['id'] === $slotId) $after = $slot;
            AdminAudit::record($pdo, $adminId, 'occupancy.' . $action, 'parking_slot', $slotId, (int) $parent['facility_id'], $reason, $summary($before), $summary($after));
            return $after;
        });
    }
}
