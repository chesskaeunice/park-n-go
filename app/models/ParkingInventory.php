<?php
declare(strict_types=1);
namespace ParkNGo\Models;

use ParkNGo\Services\Database;
use ParkNGo\Services\Parking\AvailabilityWindow;

final class ParkingInventory
{
    public function facilities(string $query = '', bool $includeInactive = false): array
    {
        $sql = 'SELECT p.*, (SELECT COUNT(*) FROM parking_floors f WHERE f.facility_id = p.id AND f.is_active = 1) AS floor_count,
            (SELECT r.initial_centavos FROM parking_rates r WHERE r.facility_id = p.id AND r.effective_from <= UTC_TIMESTAMP() ORDER BY r.effective_from DESC LIMIT 1) AS initial_centavos,
            (SELECT r.initial_minutes FROM parking_rates r WHERE r.facility_id = p.id AND r.effective_from <= UTC_TIMESTAMP() ORDER BY r.effective_from DESC LIMIT 1) AS initial_minutes
            FROM parking_facilities p WHERE ' . ($includeInactive ? '1=1' : 'p.is_active = 1') . '
            AND (? = \'\' OR LOCATE(?, CONCAT_WS(\' \', p.name, p.city, p.address)) > 0) ORDER BY p.name, p.id';
        $statement = Database::connection()->prepare($sql);
        $statement->execute([$query, $query]);
        return $statement->fetchAll();
    }

    public function facility(int $id, bool $includeInactive = false): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM parking_facilities WHERE id = ?' . ($includeInactive ? '' : ' AND is_active = 1'));
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function floors(int $facilityId, bool $includeInactive = false): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM parking_floors WHERE facility_id = ?' . ($includeInactive ? '' : ' AND is_active = 1') . ' ORDER BY sort_order, id');
        $statement->execute([$facilityId]);
        return $statement->fetchAll();
    }

    public function slots(int $floorId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM parking_slots WHERE floor_id = ? ORDER BY position_y, position_x, id');
        $statement->execute([$floorId]);
        return $statement->fetchAll();
    }

    public function availability(int $facilityId, int $floorId, AvailabilityWindow $window): array
    {
        $facility = $this->facility($facilityId);
        $floor = null;
        foreach ($this->floors($facilityId) as $candidate) if ((int) $candidate['id'] === $floorId) $floor = $candidate;
        if (!$facility || !$floor) throw new \OutOfBoundsException('The facility or floor is unavailable.');
        $sql = "SELECT s.id, s.label, s.position_x, s.position_y,
            CASE WHEN s.status = 'disabled' THEN 'disabled'
                 WHEN s.status = 'maintenance' THEN 'maintenance'
                 WHEN EXISTS (SELECT 1 FROM parking_sessions ps JOIN reservations r ON r.id = ps.reservation_id WHERE r.slot_id = s.id AND ps.status = 'active' AND ps.exit_at IS NULL AND ps.entry_at < ?) THEN 'occupied'
                 WHEN EXISTS (SELECT 1 FROM reservations r WHERE r.slot_id = s.id AND r.status = 'confirmed' AND r.starts_at < ? AND r.ends_at > ?) THEN 'reserved'
                 WHEN EXISTS (SELECT 1 FROM reservation_holds h WHERE h.slot_id = s.id AND h.released_at IS NULL AND h.expires_at > UTC_TIMESTAMP() AND h.starts_at < ? AND h.ends_at > ?) THEN 'held'
                 ELSE 'available' END AS status
            FROM parking_slots s WHERE s.floor_id = ? ORDER BY s.position_y, s.position_x, s.id";
        $start = $window->start->format('Y-m-d H:i:s'); $end = $window->end->format('Y-m-d H:i:s');
        $statement = Database::connection()->prepare($sql);
        $statement->execute([$end, $end, $start, $end, $start, $floorId]);
        $slots = $statement->fetchAll();
        $counts = array_fill_keys(['available', 'held', 'reserved', 'occupied', 'maintenance', 'disabled'], 0);
        foreach ($slots as &$slot) {
            foreach (['id', 'position_x', 'position_y'] as $key) $slot[$key] = (int) $slot[$key];
            $counts[$slot['status']]++;
        }
        unset($slot);
        // Legacy/imported data must never disappear because coordinates overlap or exceed the editor bounds.
        $positions = []; $fallback = false;
        foreach ($slots as $slot) {
            $position = $slot['position_x'] . ':' . $slot['position_y'];
            if (isset($positions[$position]) || $slot['position_x'] > 11 || $slot['position_y'] > 9) $fallback = true;
            $positions[$position] = true;
        }
        if ($fallback) foreach ($slots as $index => &$slot) { $slot['position_x'] = $index % 6; $slot['position_y'] = intdiv($index, 6); }
        unset($slot);
        $rate = Database::connection()->prepare('SELECT initial_centavos, initial_minutes FROM parking_rates WHERE facility_id = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1');
        $rate->execute([$facilityId, $start]); $pricing = $rate->fetch();
        return ['facility' => ['id' => $facilityId, 'name' => $facility['name'], 'address' => $facility['address'], 'city' => $facility['city']],
            'floor' => ['id' => $floorId, 'label' => $floor['label']], 'slots' => $slots, 'counts' => $counts,
            'rate' => $pricing ? ['initial_centavos' => (int) $pricing['initial_centavos'], 'initial_minutes' => (int) $pricing['initial_minutes']] : null,
            'window' => ['starts_at' => $window->start->format(DATE_ATOM), 'ends_at' => $window->end->format(DATE_ATOM), 'label' => $window->label()],
            'checked_at' => gmdate(DATE_ATOM), 'selection_creates_hold' => false,
            'layout_note' => $fallback ? 'List layout: stored map positions need an administrator review.' : null];
    }
}
