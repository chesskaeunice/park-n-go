<?php
declare(strict_types=1);
namespace ParkNGo\Services\Parking;

use ParkNGo\Services\{Audit, Database};

final class InventoryManager
{
    private function text(array $data, string $key, int $min, int $max): string
    {
        $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        if (mb_strlen($value) < $min || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) throw new \InvalidArgumentException('Check the ' . str_replace('_', ' ', $key) . ' field (' . $min . '–' . $max . ' characters).');
        return $value;
    }

    private function integer(array $data, string $key, int $min, int $max): int
    {
        $value = $data[$key] ?? '';
        if (!is_string($value) || !preg_match('/^-?\d+$/D', $value) || (int) $value < $min || (int) $value > $max) throw new \InvalidArgumentException('Check the ' . str_replace('_', ' ', $key) . ' field.');
        return (int) $value;
    }

    private function lock(\PDO $pdo, string $table, int $id): array
    {
        $statement = $pdo->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
        $statement->execute([$id]);
        return $statement->fetch() ?: throw new \OutOfBoundsException('The record no longer exists.');
    }

    private function assertUncommitted(\PDO $pdo, int $slotId): void
    {
        // Current locking reads see a hold committed while this mutation waited for the facility lock.
        foreach ([
            "SELECT id FROM reservations WHERE slot_id=? AND status='confirmed' AND ends_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE",
            'SELECT id FROM reservation_holds WHERE slot_id=? AND released_at IS NULL AND expires_at>UTC_TIMESTAMP() AND ends_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE',
            "SELECT p.id FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id WHERE r.slot_id=? AND p.status='active' AND p.exit_at IS NULL LIMIT 1 FOR UPDATE",
        ] as $sql) {
            $statement=$pdo->prepare($sql);$statement->execute([$slotId]);
            if ($statement->fetch()) throw new \InvalidArgumentException('This inventory has an active session, confirmed reservation, or live hold. Resolve it before changing the slot or disabling its floor/facility.');
        }
    }

    public function save(string $type, array $data, int $actorId): int
    {
        if (!in_array($type, ['facility', 'floor', 'slot'], true)) throw new \InvalidArgumentException('Unknown inventory type.');
        $id = $this->integer($data + ['id' => '0'], 'id', 0, PHP_INT_MAX);
        return Database::transaction(function (\PDO $pdo) use ($type, $data, $actorId, $id): int {
            // All inventory mutations lock facility -> floor -> slot. No reparenting is allowed.
            if ($type === 'facility') {
                $values = [$this->text($data, 'name', 2, 150), $this->text($data, 'address', 2, 255), $this->text($data, 'city', 2, 100), $this->text($data, 'description', 0, 2000), isset($data['is_active']) ? 1 : 0];
                if ($id) {
                    $this->lock($pdo, 'parking_facilities', $id);
                    if (!$values[4]) {
                        $slots = $pdo->prepare('SELECT s.id FROM parking_slots s JOIN parking_floors f ON f.id = s.floor_id WHERE f.facility_id = ? ORDER BY s.id FOR UPDATE'); $slots->execute([$id]);
                        foreach ($slots->fetchAll() as $slot) $this->assertUncommitted($pdo, (int) $slot['id']);
                    }
                    $pdo->prepare('UPDATE parking_facilities SET name = ?, address = ?, city = ?, description = ?, is_active = ? WHERE id = ?')->execute([...$values, $id]);
                } else $pdo->prepare('INSERT INTO parking_facilities (name, address, city, description, is_active) VALUES (?, ?, ?, ?, ?)')->execute($values);
            } elseif ($type === 'floor') {
                $facilityId = $this->integer($data, 'facility_id', 1, PHP_INT_MAX);
                $this->lock($pdo, 'parking_facilities', $facilityId);
                $values = [$this->text($data, 'label', 1, 50), $this->integer($data, 'sort_order', -100, 100), isset($data['is_active']) ? 1 : 0];
                if ($id) {
                    $floor = $this->lock($pdo, 'parking_floors', $id);
                    if ((int) $floor['facility_id'] !== $facilityId) throw new \InvalidArgumentException('A floor cannot be moved to another facility.');
                    if (!$values[2]) {
                        $slots = $pdo->prepare('SELECT id FROM parking_slots WHERE floor_id = ? ORDER BY id FOR UPDATE'); $slots->execute([$id]);
                        foreach ($slots->fetchAll() as $slot) $this->assertUncommitted($pdo, (int) $slot['id']);
                    }
                    $pdo->prepare('UPDATE parking_floors SET label = ?, sort_order = ?, is_active = ? WHERE id = ?')->execute([...$values, $id]);
                } else $pdo->prepare('INSERT INTO parking_floors (label, sort_order, is_active, facility_id) VALUES (?, ?, ?, ?)')->execute([...$values, $facilityId]);
            } else {
                $floorId = $this->integer($data, 'floor_id', 1, PHP_INT_MAX);
                $find = $pdo->prepare('SELECT facility_id FROM parking_floors WHERE id = ?'); $find->execute([$floorId]);
                $facilityId = $find->fetchColumn();
                if (!$facilityId) throw new \OutOfBoundsException('Floor not found.');
                $this->lock($pdo, 'parking_facilities', (int) $facilityId);
                $this->lock($pdo, 'parking_floors', $floorId);
                $status = $this->text($data, 'status', 1, 20);
                if (!in_array($status, ['available', 'maintenance', 'disabled'], true)) throw new \InvalidArgumentException('Choose an allowed slot status.');
                $values = [$this->text($data, 'label', 1, 24), $status, $this->integer($data, 'position_x', 0, 11), $this->integer($data, 'position_y', 0, 9)];
                if ($id) {
                    $slot = $this->lock($pdo, 'parking_slots', $id);
                    if ((int) $slot['floor_id'] !== $floorId) throw new \InvalidArgumentException('A slot cannot be moved to another floor.');
                    $this->assertUncommitted($pdo, $id);
                }
                $duplicate = $pdo->prepare('SELECT id FROM parking_slots WHERE floor_id = ? AND position_x = ? AND position_y = ? AND id <> ?'); $duplicate->execute([$floorId, $values[2], $values[3], $id]);
                if ($duplicate->fetch()) throw new \InvalidArgumentException('That map position already contains a slot. Choose a different column or row.');
                $pdo->prepare($id ? 'UPDATE parking_slots SET label = ?, status = ?, position_x = ?, position_y = ? WHERE id = ?' : 'INSERT INTO parking_slots (label, status, position_x, position_y, floor_id) VALUES (?, ?, ?, ?, ?)')->execute([...$values, $id ?: $floorId]);
            }
            $savedId = $id ?: (int) $pdo->lastInsertId();
            Audit::record('inventory.' . $type . ($id ? '.updated' : '.created'), $actorId, $savedId, $type);
            return $savedId;
        });
    }
}
