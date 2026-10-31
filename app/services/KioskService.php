<?php
declare(strict_types=1);

namespace ParkNGo\Services;

use PDO;

final class KioskService
{
    public static function facilities(): array
    {
        return Database::connection()->query('SELECT id, name FROM parking_facilities WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public static function listForAdmin(): array
    {
        return Database::connection()->query(
            'SELECT k.id, k.name, k.status, k.created_at, k.activated_at, k.revoked_at, f.name AS facility_name
             FROM exit_kiosks k INNER JOIN parking_facilities f ON f.id = k.facility_id ORDER BY k.created_at DESC, k.id DESC'
        )->fetchAll();
    }

    public static function create(int $adminId, int $facilityId, string $name): array
    {
        $name = trim($name);
        if ($facilityId < 1 || $name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name)) {
            throw new \InvalidArgumentException('Enter a valid kiosk name and facility.');
        }
        if (!RateLimiter::consume('kiosk-create', (string) $adminId, 20, 900)) {
            throw new \InvalidArgumentException('Too many kiosk registration attempts. Please try again later.');
        }

        $code = strtoupper(bin2hex(random_bytes(16)));
        $codeHash = hash('sha256', $code);
        return Database::transaction(function (PDO $pdo) use ($adminId, $facilityId, $name, $code, $codeHash): array {
            $admin = $pdo->prepare("SELECT role, status FROM users WHERE id = ? FOR UPDATE");
            $admin->execute([$adminId]);
            $adminRow = $admin->fetch();
            if (!$adminRow || $adminRow['status'] !== 'active' || $adminRow['role'] !== 'admin') {
                throw new \InvalidArgumentException('Administrator authorization is required.');
            }
            $facility = $pdo->prepare('SELECT id FROM parking_facilities WHERE id = ? AND is_active = 1 FOR UPDATE');
            $facility->execute([$facilityId]);
            if (!$facility->fetch()) throw new \InvalidArgumentException('The selected facility is unavailable.');

            $insert = $pdo->prepare('INSERT INTO exit_kiosks (facility_id, name, created_by) VALUES (?, ?, ?)');
            $insert->execute([$facilityId, $name, $adminId]);
            $kioskId = (int) $pdo->lastInsertId();
            $activation = $pdo->prepare('INSERT INTO kiosk_activation_codes (kiosk_id, code_hash, expires_at, created_by) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), ?)');
            $activation->execute([$kioskId, $codeHash, $adminId]);
            Audit::record('kiosk.created', $adminId, $kioskId, 'exit_kiosk');
            Audit::record('kiosk.activation_code_created', $adminId, $kioskId, 'exit_kiosk');
            \ParkNGo\Services\Admin\AdminAudit::record($pdo, $adminId, 'kiosk.registered', 'exit_kiosk', $kioskId, $facilityId, null, null, ['name' => $name, 'status' => 'pending']);
            return ['id' => $kioskId, 'name' => $name, 'code' => $code];
        });
    }

    public static function activate(string $submittedCode): array
    {
        $code = strtoupper(trim($submittedCode));
        if (!preg_match('/^[A-F0-9]{32}$/', $code)) throw new \InvalidArgumentException('Activation code is invalid or expired.');
        $codeHash = hash('sha256', $code);
        $credential = bin2hex(random_bytes(32));
        return Database::transaction(function (PDO $pdo) use ($codeHash, $credential): array {
            $query = $pdo->prepare(
                'SELECT a.id AS activation_id, a.kiosk_id, a.expires_at, a.consumed_at, k.facility_id, k.name, k.status
                 FROM kiosk_activation_codes a INNER JOIN exit_kiosks k ON k.id = a.kiosk_id
                 WHERE a.code_hash = ? FOR UPDATE'
            );
            $query->execute([$codeHash]);
            $row = $query->fetch();
            if (!$row || $row['consumed_at'] !== null || $row['status'] === 'revoked' || strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
                throw new \InvalidArgumentException('Activation code is invalid or expired.');
            }
            $consume = $pdo->prepare('UPDATE kiosk_activation_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ? AND consumed_at IS NULL');
            $consume->execute([$row['activation_id']]);
            if ($consume->rowCount() !== 1) throw new \InvalidArgumentException('Activation code is invalid or expired.');
            $update = $pdo->prepare("UPDATE exit_kiosks SET status = 'active', credential_hash = ?, activated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'");
            $update->execute([hash('sha256', $credential), $row['kiosk_id']]);
            if ($update->rowCount() !== 1) throw new \InvalidArgumentException('Activation code is invalid or expired.');
            Audit::record('kiosk.activation_succeeded', null, (int) $row['kiosk_id'], 'exit_kiosk');
            return ['id' => (int) $row['kiosk_id'], 'facility_id' => (int) $row['facility_id'], 'name' => $row['name'], 'credential' => $credential];
        });
    }

    public static function revoke(int $adminId, int $kioskId): void
    {
        if ($kioskId < 1) throw new \InvalidArgumentException('Kiosk not found.');
        Database::transaction(function (PDO $pdo) use ($adminId, $kioskId): void {
            $admin = $pdo->prepare("SELECT role, status FROM users WHERE id = ? FOR UPDATE");
            $admin->execute([$adminId]);
            $adminRow = $admin->fetch();
            if (!$adminRow || $adminRow['status'] !== 'active' || $adminRow['role'] !== 'admin') throw new \InvalidArgumentException('Administrator authorization is required.');
            $lock = $pdo->prepare('SELECT id, status, facility_id FROM exit_kiosks WHERE id = ? FOR UPDATE');
            $lock->execute([$kioskId]);
            $kiosk = $lock->fetch();
            if (!$kiosk) throw new \OutOfBoundsException('Kiosk not found.');
            $pdo->prepare("UPDATE exit_kiosks SET status = 'revoked', revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE id = ? AND status <> 'revoked'")->execute([$adminId, $kioskId]);
            Audit::record('kiosk.revoked', $adminId, $kioskId, 'exit_kiosk');
            \ParkNGo\Services\Admin\AdminAudit::record($pdo, $adminId, 'kiosk.revoked', 'exit_kiosk', $kioskId, (int) $kiosk['facility_id'], null, ['status' => $kiosk['status']], ['status' => 'revoked']);
        });
    }
}
