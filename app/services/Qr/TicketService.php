<?php
declare(strict_types=1);
namespace ParkNGo\Services\Qr;

use ParkNGo\Services\{Audit, Database};

final class TicketService
{
    private static function hash(string $value): string { return hash('sha256', $value); }

    public function qrDataUri(string $token): string
    {
        $qrCode = new \Endroid\QrCode\QrCode(
            data: $token,
            encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::High,
            size: 320,
            margin: 16,
            roundBlockSizeMode: \Endroid\QrCode\RoundBlockSizeMode::Margin,
            foregroundColor: new \Endroid\QrCode\Color\Color(0, 0, 0),
            backgroundColor: new \Endroid\QrCode\Color\Color(255, 255, 255),
        );
        return (new \Endroid\QrCode\Writer\PngWriter())->write($qrCode)->getDataUri();
    }

    public function issue(int $userId, int $reservationId): array
    {
        return Database::transaction(function (\PDO $pdo) use ($userId, $reservationId): array {
            $find = $pdo->prepare('SELECT r.*,s.label AS slot_label,fl.label AS floor_label,f.id AS facility_id,f.name AS facility_name FROM reservations r JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id WHERE r.id=? AND r.user_id=? FOR UPDATE');
            $find->execute([$reservationId, $userId]); $reservation = $find->fetch();
            if (!$reservation) throw new \OutOfBoundsException('Reservation not found.');
            $payment = $pdo->prepare("SELECT 1 FROM payment_attempts WHERE reservation_id=? AND payment_type='INITIAL_RESERVATION' AND local_status='verified' LIMIT 1"); $payment->execute([$reservationId]);
            $eligible = $reservation['status'] === 'confirmed' && (bool) $payment->fetchColumn();
            $ticket = null;
            if ($eligible) {
                $token = 'PNGO-' . strtoupper(bin2hex(random_bytes(32)));
                $manual = strtoupper(substr(str_replace(['/', '+', '='], '', base64_encode(random_bytes(9))), 0, 12));
                $expires = (new \DateTimeImmutable($reservation['ends_at'], new \DateTimeZone('UTC')))->modify('+30 minutes')->format('Y-m-d H:i:s');
                $pdo->prepare("INSERT INTO qr_tickets (reservation_id,token_hash,manual_code_hash,status,expires_at,last_issued_at) VALUES (?,?,?,'active',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),manual_code_hash=VALUES(manual_code_hash),status='active',expires_at=VALUES(expires_at),last_issued_at=UTC_TIMESTAMP(),revoked_at=NULL")->execute([$reservationId, self::hash($token), self::hash($manual), $expires]);
                $ticket = ['token' => $token, 'manual_code' => $manual, 'expires_at' => $expires, 'status' => 'active'];
                Audit::record('qr.ticket_issued', $userId, $reservationId, 'reservation');
            }
            return ['reservation' => $reservation, 'ticket' => $ticket, 'eligible' => $eligible];
        });
    }

    /** Exit lookups accept a ticket past its entry expiry only while its own parking session is still active. */
    public function resolve(string $submitted, bool $forExit = false): array
    {
        $value = strtoupper(trim($submitted));
        if (str_starts_with($value, 'PNGO:')) $value = substr($value, 5);
        if (!preg_match('/^[A-Z0-9-]{8,80}$/D', $value)) throw new \InvalidArgumentException('Enter a valid ticket token or manual code.');
        return $this->resolveHash(self::hash($value), $forExit);
    }

    public function resolveTicketId(int $ticketId, bool $forExit = false): array
    {
        if ($ticketId < 1) throw new \InvalidArgumentException('This ticket is invalid or expired.');
        $find = Database::connection()->prepare('SELECT t.*,r.user_id,r.slot_id,r.starts_at,r.ends_at,r.status AS reservation_status,r.reference,s.label AS slot_label,fl.label AS floor_label,f.id AS facility_id,f.name AS facility_name FROM qr_tickets t JOIN reservations r ON r.id=t.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id WHERE t.id=? LIMIT 1');
        $find->execute([$ticketId]);
        return $this->validateResolved($find->fetch(), $forExit);
    }

    private function resolveHash(string $hash, bool $forExit): array
    {
        $find = Database::connection()->prepare('SELECT t.*,r.user_id,r.slot_id,r.starts_at,r.ends_at,r.status AS reservation_status,r.reference,s.label AS slot_label,fl.label AS floor_label,f.id AS facility_id,f.name AS facility_name FROM qr_tickets t JOIN reservations r ON r.id=t.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities f ON f.id=fl.facility_id WHERE (t.token_hash=? OR t.manual_code_hash=?) LIMIT 1');
        $find->execute([$hash, $hash]);
        return $this->validateResolved($find->fetch(), $forExit);
    }

    private function validateResolved(?array $ticket, bool $forExit): array
    {
        $expired = $ticket && $ticket['expires_at'] <= gmdate('Y-m-d H:i:s');
        if ($expired && $forExit) {
            // Extended or overtime sessions outlive the entry-window expiry; the same ticket must still exit.
            $active = Database::connection()->prepare("SELECT 1 FROM parking_sessions WHERE reservation_id=? AND status='active' AND exit_at IS NULL");
            $active->execute([$ticket['reservation_id']]);
            $expired = !$active->fetchColumn();
        }
        if (!$ticket || $ticket['status'] !== 'active' || $ticket['revoked_at'] !== null || $expired) throw new \InvalidArgumentException('This ticket is invalid or expired.');
        if ($ticket['reservation_status'] !== 'confirmed') throw new \InvalidArgumentException('This reservation is not payment-confirmed.');
        return $ticket;
    }
}
