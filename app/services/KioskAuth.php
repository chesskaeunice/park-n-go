<?php
declare(strict_types=1);

namespace ParkNGo\Services;

use PDO;

final class KioskAuth
{
    public static function establish(int $kioskId, string $credential): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Kiosk session is unavailable.');
        }
        session_regenerate_id(true);
        $_SESSION = [
            'kiosk_id' => $kioskId,
            'kiosk_credential' => $credential,
            'kiosk_authenticated_at' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public static function current(): ?array
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || isset($_SESSION['user_id'])) return null;
        $kioskId = filter_var($_SESSION['kiosk_id'] ?? null, FILTER_VALIDATE_INT);
        $credential = $_SESSION['kiosk_credential'] ?? null;
        if (!$kioskId || !is_string($credential) || strlen($credential) !== 64) return null;

        $query = Database::connection()->prepare(
            'SELECT k.id, k.facility_id, k.name, k.status, k.credential_hash, f.name AS facility_name
             FROM exit_kiosks k INNER JOIN parking_facilities f ON f.id = k.facility_id
             WHERE k.id = ? AND f.is_active = 1 LIMIT 1'
        );
        $query->execute([$kioskId]);
        $kiosk = $query->fetch();
        $hash = hash('sha256', $credential);
        if (!$kiosk || $kiosk['status'] !== 'active' || !is_string($kiosk['credential_hash']) || !hash_equals($kiosk['credential_hash'], $hash)) {
            self::clear();
            return null;
        }
        Database::connection()->prepare('UPDATE exit_kiosks SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$kioskId]);
        return $kiosk;
    }

    public static function transactionCurrent(PDO $pdo): ?array
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || isset($_SESSION['user_id'])) return null;
        $kioskId = filter_var($_SESSION['kiosk_id'] ?? null, FILTER_VALIDATE_INT);
        $credential = $_SESSION['kiosk_credential'] ?? null;
        if (!$kioskId || !is_string($credential) || strlen($credential) !== 64) return null;
        $query = $pdo->prepare(
            'SELECT k.id, k.facility_id, k.name, k.status, k.credential_hash, f.name AS facility_name
             FROM exit_kiosks k INNER JOIN parking_facilities f ON f.id = k.facility_id
             WHERE k.id = ? AND f.is_active = 1 FOR UPDATE'
        );
        $query->execute([$kioskId]);
        $kiosk = $query->fetch();
        if (!$kiosk || $kiosk['status'] !== 'active' || !is_string($kiosk['credential_hash']) || !hash_equals($kiosk['credential_hash'], hash('sha256', $credential))) return null;
        $pdo->prepare('UPDATE exit_kiosks SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$kioskId]);
        return $kiosk;
    }

    public static function clear(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }
}
