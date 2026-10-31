<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\{Clock, Database};

/**
 * Prospective facility pricing. parking_rates is already versioned by effective_from and every reservation
 * keeps its rate_id and rate_snapshot, so a change is always a NEW row effective now or later. Existing rows
 * are never updated: verified payments, active sessions (extension and overtime rates come from the
 * reservation's rate row), settled overtime, purchased extensions, and payment attempts are untouched.
 */
final class PricingService
{
    /** Money fields an admin may set, all in integer centavos. Grace and cancellation policy carry forward unchanged. */
    public const FIELDS = [
        'initial_centavos' => 'Initial period rate',
        'succeeding_hour_centavos' => 'Succeeding hour and extension rate',
        'overtime_hour_centavos' => 'Overtime hourly rate',
        'daily_max_centavos' => 'Daily maximum (optional)',
        'additional_centavos' => 'Additional charge',
    ];

    public function overview(int $facilityId, ?\DateTimeImmutable $now = null): array
    {
        $at = ($now ?? Clock::now())->format('Y-m-d H:i:s');
        $find = Database::connection()->prepare('SELECT pr.*,u.name AS created_by_name,(SELECT COUNT(*) FROM reservations r WHERE r.rate_id=pr.id) AS reservation_count FROM parking_rates pr LEFT JOIN users u ON u.id=pr.created_by WHERE pr.facility_id=? ORDER BY pr.effective_from DESC,pr.id DESC LIMIT 50');
        $find->execute([$facilityId]); $history = $find->fetchAll();
        $current = null; $scheduled = [];
        foreach ($history as $row) {
            if ($row['effective_from'] > $at) $scheduled[] = $row;
            elseif ($current === null) $current = $row;
        }
        return ['current' => $current, 'scheduled' => $scheduled, 'history' => $history, 'latest_id' => $history ? (int) $history[0]['id'] : 0];
    }

    /**
     * $effective is a Manila 'Y-m-d\TH:i' value or '' for "now". $expectedLatest is the newest rate id the
     * admin saw; a concurrent change makes this request fail instead of silently stacking on top.
     */
    public function schedule(int $adminId, int $facilityId, array $input, string $effective, string $reason, int $expectedLatest): array
    {
        $reason = Reason::require($reason);
        $values = [];
        foreach (self::FIELDS as $field => $label) {
            $raw = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if ($field === 'daily_max_centavos' && $raw === '') { $values[$field] = null; continue; }
            $values[$field] = Reason::centavos($raw, strtolower($label), $field === 'additional_centavos' ? 0 : 1);
        }
        $minutes = is_string($input['initial_minutes'] ?? null) && preg_match('/^\d{1,4}$/D', $input['initial_minutes']) ? (int) $input['initial_minutes'] : 0;
        if ($minutes < 15 || $minutes > 1440) throw new \InvalidArgumentException('The initial period must be 15–1440 minutes.');
        if ($values['daily_max_centavos'] !== null && $values['daily_max_centavos'] < $values['initial_centavos']) throw new \InvalidArgumentException('The daily maximum cannot be lower than the initial period rate.');
        $now = Clock::now();
        if ($effective === '') $from = $now;
        else {
            $local = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $effective, new \DateTimeZone('Asia/Manila'));
            if (!$local || $local->format('Y-m-d\TH:i') !== $effective) throw new \InvalidArgumentException('Choose a valid effective date and time.');
            $from = $local->setTimezone(new \DateTimeZone('UTC'));
            if ($from < $now->modify('-59 seconds')) throw new \InvalidArgumentException('Pricing changes cannot be backdated. Choose now or a future time.');
            if ($from < $now) $from = $now;
            if ($from > $now->modify('+1 year')) throw new \InvalidArgumentException('Schedule pricing changes at most one year ahead.');
        }
        $fromText = $from->format('Y-m-d H:i:s');
        try {
            return Database::transaction(function (\PDO $pdo) use ($adminId, $facilityId, $values, $minutes, $fromText, $reason, $expectedLatest): array {
                AdminPermissions::authorize($pdo, $adminId, 'manage_pricing');
                // Same first lock as reservation pricing and inventory changes: the facility row.
                $facility = $pdo->prepare('SELECT id,name FROM parking_facilities WHERE id=? FOR UPDATE'); $facility->execute([$facilityId]);
                if (!$facility->fetch()) throw new \OutOfBoundsException('Facility not found.');
                $latest = $pdo->prepare('SELECT * FROM parking_rates WHERE facility_id=? ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE'); $latest->execute([$facilityId]); $latest = $latest->fetch() ?: null;
                if ((int) ($latest['id'] ?? 0) !== $expectedLatest) throw new \InvalidArgumentException('Pricing for this facility changed while you were editing. Review the current rates and try again.');
                if ($latest && $fromText <= $latest['effective_from']) throw new \InvalidArgumentException('The new rate must take effect after the latest scheduled rate (' . $latest['effective_from'] . ' UTC).');
                $pdo->prepare('INSERT INTO parking_rates (facility_id,initial_minutes,initial_centavos,succeeding_hour_centavos,overtime_hour_centavos,grace_minutes,daily_max_centavos,additional_centavos,cancellation_cutoff_minutes,effective_from,created_by,change_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$facilityId, $minutes, $values['initial_centavos'], $values['succeeding_hour_centavos'], $values['overtime_hour_centavos'], (int) ($latest['grace_minutes'] ?? 15),
                        $values['daily_max_centavos'], $values['additional_centavos'], (int) ($latest['cancellation_cutoff_minutes'] ?? 60), $fromText, $adminId, $reason]);
                $id = (int) $pdo->lastInsertId();
                $fields = ['initial_minutes', ...array_keys(self::FIELDS), 'grace_minutes', 'cancellation_cutoff_minutes', 'effective_from'];
                $pick = static fn (?array $row): ?array => $row === null ? null : array_intersect_key($row, array_flip(['id', ...$fields]));
                $after = ['id' => $id, 'initial_minutes' => $minutes] + $values + ['grace_minutes' => (int) ($latest['grace_minutes'] ?? 15), 'cancellation_cutoff_minutes' => (int) ($latest['cancellation_cutoff_minutes'] ?? 60), 'effective_from' => $fromText];
                AdminAudit::record($pdo, $adminId, 'pricing.rate_scheduled', 'parking_rate', $id, $facilityId, $reason, $pick($latest), $after);
                return $after;
            });
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new \InvalidArgumentException('A rate already takes effect at that exact time. Choose another time.');
            throw $error;
        }
    }
}
