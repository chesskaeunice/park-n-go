<?php
declare(strict_types=1);
namespace ParkNGo\Controllers;

use ParkNGo\Models\ParkingInventory;
use ParkNGo\Services\{Auth, Database, RateLimiter};
use ParkNGo\Services\Parking\{AvailabilityWindow, InventoryManager};

final class ParkingController
{
    public static function id(array $query, string $key, int $default = 0): int
    {
        if (!isset($query[$key]) || $query[$key] === '') return $default;
        if (!is_string($query[$key]) || !preg_match('/^[1-9]\d{0,17}$/D', $query[$key])) throw new \InvalidArgumentException('Choose a valid ' . str_replace('_', ' ', $key) . '.');
        return (int) $query[$key];
    }

    public function handle(string $page): array
    {
        $model = new ParkingInventory();
        if (str_starts_with($page, 'admin-')) return $this->admin($page, $model);
        $data = ['errors' => []];
        try { $window = new AvailabilityWindow($_GET); }
        catch (\InvalidArgumentException $error) { $data['errors'][] = $error->getMessage(); http_response_code(422); $window = new AvailabilityWindow([]); }
        $data['window'] = $window;
        if ($page === 'facilities') {
            $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
            return $data + ['query' => $query, 'facilities' => $model->facilities($query)];
        }
        $id = self::id($_GET, 'facility_id');
        $facility = $model->facility($id);
        if (!$facility) throw new \OutOfBoundsException('Parking facility not found.');
        $floors = $model->floors($id);
        $floorId = self::id($_GET, 'floor_id', $floors ? (int) $floors[0]['id'] : 0);
        $availability = $floors && !$data['errors'] ? $model->availability($id, $floorId, $window) : null;
        $selection = null;
        $slotId = self::id($_GET, 'slot_id');
        if ($slotId && $availability) {
            foreach ($availability['slots'] as $slot) if ($slot['id'] === $slotId && $slot['status'] === 'available') $selection = $slot;
            if (!$selection) { $data['errors'][] = 'That space is no longer available for this time window. Choose another space.'; http_response_code(409); }
        }
        return $data + compact('facility', 'floors', 'floorId', 'availability', 'selection');
    }

    private function admin(string $page, ParkingInventory $model): array
    {
        $type = match ($page) { 'admin-facilities' => 'facility', 'admin-floors' => 'floor', default => 'slot' };
        $errors = []; $old = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $old = $_POST;
            try {
                if (!RateLimiter::consume('inventory-admin', (string) Auth::user()['id'], 120)) {
                    http_response_code(429); header('Retry-After: 900'); throw new \InvalidArgumentException('Too many changes. Try again in 15 minutes.');
                }
                (new InventoryManager())->save($type, $_POST, (int) Auth::user()['id']);
                flash(ucfirst($type) . ' saved.');
                $parameters = $type === 'floor' ? ['facility_id' => input('facility_id')] : ($type === 'slot' ? ['floor_id' => input('floor_id')] : []);
                redirect($page, $parameters);
            } catch (\InvalidArgumentException|\OutOfBoundsException $error) { $errors[] = $error->getMessage(); }
            catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? 0) !== 1062) throw $error;
                $errors[] = 'That label already exists in this facility or floor. Choose a unique label.';
            }
            if (http_response_code() !== 429) http_response_code(422);
        }
        $facilities = $model->facilities('', true);
        $facilityId = self::id($_GET, 'facility_id', $facilities ? (int) $facilities[0]['id'] : 0);
        $floorId = self::id($_GET, 'floor_id');
        if ($type === 'slot' && $floorId) {
            $find = Database::connection()->prepare('SELECT facility_id FROM parking_floors WHERE id = ?'); $find->execute([$floorId]);
            $parent = $find->fetchColumn(); if (!$parent) throw new \OutOfBoundsException('Floor not found.'); $facilityId = (int) $parent;
        }
        if ($type !== 'facility' && $facilityId && !$model->facility($facilityId, true)) throw new \OutOfBoundsException('Facility not found.');
        $floors = $facilityId ? $model->floors($facilityId, true) : [];
        if (!$floorId && $floors) $floorId = (int) $floors[0]['id'];
        $records = match ($type) { 'facility' => $facilities, 'floor' => $floors, 'slot' => $floorId ? $model->slots($floorId) : [] };
        $editId = self::id($_GET, 'edit'); $editing = null;
        foreach ($records as $record) if ((int) $record['id'] === $editId) $editing = $record;
        if ($editId && !$editing) throw new \OutOfBoundsException('Record not found in this inventory.');
        if (!$old) $old = $editing ?? [];
        return compact('type', 'errors', 'old', 'facilities', 'floors', 'facilityId', 'floorId', 'records', 'editing');
    }
}
