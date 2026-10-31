<?php
declare(strict_types=1);
namespace ParkNGo\Services\Parking;

use ParkNGo\Services\Clock;

final class OvertimeService
{
    public function calculate(array $session, ?string $now = null, ?\PDO $pdo = null): array
    {
        $current = $now === null ? Clock::now() : new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
        $paidUntil = new \DateTimeImmutable($session['paid_until'], new \DateTimeZone('UTC'));
        $chargeableFrom = $paidUntil;
        $graceUntil = null;
        if ($pdo && !empty($session['id'])) {
            $find = $pdo->prepare("SELECT grace_until FROM parking_overtime_settlements WHERE parking_session_id=? AND status='verified' AND grace_until IS NOT NULL ORDER BY grace_until DESC LIMIT 1");
            $find->execute([(int) $session['id']]);
            $storedGrace = $find->fetchColumn();
            if (is_string($storedGrace)) {
                $graceUntil = new \DateTimeImmutable($storedGrace, new \DateTimeZone('UTC'));
                if ($graceUntil > $chargeableFrom) $chargeableFrom = $graceUntil;
            }
        } elseif (!empty($session['grace_until'])) {
            $graceUntil = new \DateTimeImmutable((string) $session['grace_until'], new \DateTimeZone('UTC'));
            if ($graceUntil > $chargeableFrom) $chargeableFrom = $graceUntil;
        }
        $elapsedSeconds = max(0, $current->getTimestamp() - $chargeableFrom->getTimestamp());
        $rate = isset($session['overtime_hour_centavos']) && $session['overtime_hour_centavos'] !== null ? (int) $session['overtime_hour_centavos'] : null;
        $amount = $rate === null || $elapsedSeconds === 0 ? ($elapsedSeconds === 0 ? 0 : null) : intdiv($rate * $elapsedSeconds + 3599, 3600);
        return [
            'paid_until' => $paidUntil->format('Y-m-d H:i:s'),
            'chargeable_from' => $chargeableFrom->format('Y-m-d H:i:s'),
            'server_now' => $current->format('Y-m-d H:i:s'),
            'elapsed_seconds' => $elapsedSeconds,
            'overtime_minutes' => intdiv($elapsedSeconds + 59, 60),
            'rate_centavos' => $rate,
            'estimated_centavos' => $amount,
            'grace_until' => $graceUntil?->format('Y-m-d H:i:s'),
            'payment_required' => $elapsedSeconds > 0,
        ];
    }
}
