<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use ParkNGo\Services\{Auth, Database, Environment};

if (Environment::get('APP_ENV', 'local') !== 'local') { fwrite(STDERR, "Seeds are restricted to APP_ENV=local.\n"); exit(1); }
try {
    $resetDemoPasswords = in_array('--reset-demo-passwords', $argv ?? [], true);
    Database::transaction(function (PDO $pdo) use ($resetDemoPasswords): void {
        foreach (['driver', 'attendant', 'admin'] as $role) {
            $email = $role . '@parkngo.test';
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetchColumn()) {
                if ($resetDemoPasswords) {
                    $password = bin2hex(random_bytes(10)) . '!aA';
                    $pdo->prepare('UPDATE users SET password_hash=?, status=\'active\', role=? WHERE email=?')->execute([Auth::hashPassword($password), $role, $email]);
                    echo "Reset local-only $role: $email | new password: $password\n";
                } else {
                    echo "Existing account preserved: $email\n";
                }
                continue;
            }
            $password = bin2hex(random_bytes(10)) . '!aA';
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')->execute([ucfirst($role) . ' Demo', $email, Auth::hashPassword($password), $role]);
            echo "Local-only $role: $email | initial password: $password\n";
        }
        foreach ([['Central Square Parking','Makati',5000,2],['Northside Parking Hub','Quezon City',4000,1],['Bayfront Parking','Pasay',6000,2]] as [$name,$city,$rate,$floorCount]) {
            $check = $pdo->prepare('SELECT id FROM parking_facilities WHERE name = ?');
            $check->execute([$name]);
            if ($check->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO parking_facilities (name, address, city, description) VALUES (?, ?, ?, ?)')->execute([$name, 'Illustrative development address', $city, 'Sample data, not an operational facility.']);
            $facilityId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO parking_rates (facility_id, initial_centavos, succeeding_hour_centavos, effective_from) VALUES (?, ?, ?, ?)')->execute([$facilityId, $rate, 2000, '2026-01-01 00:00:00']);
            for ($floor = 0; $floor < $floorCount; $floor++) {
                $pdo->prepare('INSERT INTO parking_floors (facility_id, label, sort_order) VALUES (?, ?, ?)')->execute([$facilityId, 'Level ' . ($floor + 1), $floor]);
                $floorId = (int) $pdo->lastInsertId();
                for ($slot = 1; $slot <= 12; $slot++) {
                    $pdo->prepare('INSERT INTO parking_slots (floor_id, label, status, position_x, position_y) VALUES (?, ?, ?, ?, ?)')->execute([$floorId, chr(65 + $floor) . '-' . str_pad((string) $slot, 2, '0', STR_PAD_LEFT), $slot === 9 ? 'maintenance' : 'available', ($slot - 1) % 6, intdiv($slot - 1, 6)]);
                }
            }
        }
        $attendant = $pdo->prepare('SELECT id FROM users WHERE email=? AND role=\'attendant\''); $attendant->execute(['attendant@parkngo.test']); $attendantId = $attendant->fetchColumn();
        if ($attendantId) {
            $facilities = $pdo->query('SELECT id FROM parking_facilities WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN);
            $grant = $pdo->prepare('INSERT IGNORE INTO attendant_facilities(attendant_id,facility_id) VALUES(?,?)');
            foreach ($facilities as $facilityId) $grant->execute([(int) $attendantId, (int) $facilityId]);
        }
        echo "Development inventory seeded. No reservations or payments created.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Seeding failed: ' . $error->getMessage() . "\n"); exit(1);
}
