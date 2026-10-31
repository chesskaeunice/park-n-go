<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\{Clock, Database, RateLimiter};
use ParkNGo\Services\Payments\PaymentService;

/**
 * Reconciliation queue derived from authoritative payment and session records. Cases are never edited:
 * admins append notes, provider rechecks, and reviews (reconciliation_actions). A review applies to the
 * exact record state it saw (fingerprint); if the payment changes later, the case reopens.
 */
final class ReconciliationService
{
    public const KINDS = [
        'late_payment' => 'Late successful payment',
        'unfulfilled_overtime' => 'Unfulfilled overtime settlement',
        'unfulfilled_extension' => 'Unfulfilled paid extension',
        'unfulfillable_reservation' => 'Payment after hold ended',
        'duplicate_provider' => 'Duplicate provider notification',
        'provider_mismatch' => 'Provider/local status mismatch',
        'stale_pending' => 'Pending beyond expected time',
        'expired_hold_unresolved' => 'Expired hold with unresolved checkout',
        'provider_error' => 'Provider API error',
        'manual_investigation' => 'Requires manual investigation',
        'missing_provider_reference' => 'Missing provider reference',
        'fulfillment_failed' => 'Verified payment with failed fulfilment',
        'unsettled_obligation' => 'Unsettled overtime after session exception',
    ];

    public function cases(int $facilityId, bool $includeReviewed = false, ?\DateTimeImmutable $now = null): array
    {
        $pdo = Database::connection(); $at = ($now ?? Clock::now())->format('Y-m-d H:i:s');
        $find = $pdo->prepare("SELECT pa.id,pa.reference,pa.payment_type,pa.amount_centavos,pa.currency,pa.local_status,pa.provider_status,pa.maya_checkout_id,pa.failure_reason,pa.reconciliation_reason,
                pa.created_at,pa.updated_at,pa.checkout_expires_at,pa.parking_session_id,ar.id AS reservation_id,ar.reference AS reservation_reference,ar.status AS reservation_status,af.id AS facility_id,af.name AS facility_name,
                COALESCE(e.status,o.status) AS ledger_status
            FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . "
            LEFT JOIN parking_extensions e ON e.payment_attempt_id=pa.id LEFT JOIN parking_overtime_settlements o ON o.payment_attempt_id=pa.id
            WHERE (?=0 OR af.id=?) AND (pa.local_status='reconciliation' OR pa.reconciliation_reason IS NOT NULL
                OR (pa.local_status IN " . OperationsMonitor::PENDING_ATTEMPT . " AND COALESCE(pa.checkout_expires_at,DATE_ADD(pa.created_at,INTERVAL 15 MINUTE))<?)
                OR (pa.local_status='pending_verification' AND pa.failure_reason IS NOT NULL)
                OR (pa.local_status='failed' AND pa.failure_reason='Checkout creation failed.')
                OR (pa.local_status IN ('checkout_created','pending_verification','verified','reconciliation') AND pa.maya_checkout_id IS NULL)
                OR (pa.local_status='verified' AND ((pa.payment_type='INITIAL_RESERVATION' AND ar.status NOT IN ('confirmed','completed','no_show')) OR (pa.payment_type<>'INITIAL_RESERVATION' AND COALESCE(e.status,o.status,'missing')<>'verified'))))
            ORDER BY pa.updated_at DESC,pa.id DESC LIMIT 1000");
        $find->execute([$facilityId, $facilityId, $at]);
        $cases = [];
        foreach ($find->fetchAll() as $row) {
            $kinds = $this->kinds($row, $at);
            if (!$kinds) continue;
            $cases[] = $row + ['key' => 'attempt:' . $row['id'], 'kinds' => $kinds, 'reason' => $row['reconciliation_reason'] ?? $row['failure_reason'] ?? self::KINDS[$kinds[0]],
                'fingerprint' => hash('sha256', json_encode([$row['local_status'], $row['provider_status'], $row['reconciliation_reason'], $row['failure_reason'], $row['maya_checkout_id'] !== null, $row['ledger_status'], $row['reservation_status'], $kinds], JSON_THROW_ON_ERROR)),
                'can_recheck' => $row['maya_checkout_id'] !== null, 'source' => 'payment'];
        }
        $find = $pdo->prepare("SELECT e.*,f.name AS facility_name,r.reference AS reservation_reference,r.id AS reservation_id FROM parking_session_exceptions e JOIN parking_facilities f ON f.id=e.facility_id
            JOIN parking_sessions p ON p.id=e.parking_session_id JOIN reservations r ON r.id=p.reservation_id WHERE e.obligation_status='unsettled' AND (?=0 OR e.facility_id=?) ORDER BY e.id DESC LIMIT 500");
        $find->execute([$facilityId, $facilityId]);
        foreach ($find->fetchAll() as $row) {
            $cases[] = ['key' => 'exception:' . $row['id'], 'id' => (int) $row['id'], 'kinds' => ['unsettled_obligation'], 'source' => 'exception', 'reference' => 'EXC-' . $row['id'],
                'payment_type' => 'OVERTIME_SETTLEMENT', 'amount_centavos' => $row['outstanding_centavos'], 'currency' => 'PHP', 'local_status' => 'unsettled', 'provider_status' => null, 'maya_checkout_id' => null,
                'reason' => 'Session closed by exception with ' . intdiv((int) $row['outstanding_seconds'] + 59, 60) . ' minutes of unpaid overtime. ' . $row['reason'],
                'parking_session_id' => (int) $row['parking_session_id'], 'reservation_id' => (int) $row['reservation_id'], 'reservation_reference' => $row['reservation_reference'],
                'facility_id' => (int) $row['facility_id'], 'facility_name' => $row['facility_name'], 'created_at' => $row['created_at'], 'updated_at' => $row['closed_at'],
                'fingerprint' => hash('sha256', json_encode(['exception', $row['id'], $row['obligation_status'], $row['outstanding_centavos']], JSON_THROW_ON_ERROR)), 'can_recheck' => false];
        }
        $this->attachHistory($cases);
        return $includeReviewed ? $cases : array_values(array_filter($cases, static fn (array $case): bool => !$case['reviewed']));
    }

    private function kinds(array $row, string $at): array
    {
        $kinds = []; $reason = (string) $row['reconciliation_reason']; $pending = in_array($row['local_status'], ['created', 'checkout_created', 'pending_verification'], true);
        if ($row['local_status'] === 'reconciliation') {
            if (str_contains($reason, 'no longer fulfillable')) $kinds[] = 'late_payment';
            elseif ($row['payment_type'] === 'OVERTIME_SETTLEMENT') $kinds[] = 'unfulfilled_overtime';
            elseif ($row['payment_type'] === 'PARKING_EXTENSION') $kinds[] = 'unfulfilled_extension';
            else $kinds[] = 'unfulfillable_reservation';
        }
        if (str_starts_with($reason, 'Provider/local mismatch')) $kinds[] = 'provider_mismatch';
        elseif ($row['local_status'] === 'verified' && $reason !== '') $kinds[] = 'duplicate_provider';
        $deadline = $row['checkout_expires_at'] ?? (new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))->modify('+15 minutes')->format('Y-m-d H:i:s');
        if ($pending && $deadline < $at) $kinds[] = $row['reservation_status'] === 'expired' && $row['payment_type'] === 'INITIAL_RESERVATION' ? 'expired_hold_unresolved' : 'stale_pending';
        if ($row['local_status'] === 'pending_verification' && str_starts_with((string) $row['failure_reason'], 'Maya verification unavailable')) $kinds[] = 'provider_error';
        if ($row['local_status'] === 'pending_verification' && $row['failure_reason'] === 'Maya verification requires review.') $kinds[] = 'manual_investigation';
        if ($row['local_status'] === 'failed' && $row['failure_reason'] === 'Checkout creation failed.') $kinds[] = 'provider_error';
        if (in_array($row['local_status'], ['checkout_created', 'pending_verification', 'verified', 'reconciliation'], true) && $row['maya_checkout_id'] === null) $kinds[] = 'missing_provider_reference';
        if ($row['local_status'] === 'verified' && ($row['payment_type'] === 'INITIAL_RESERVATION' ? !in_array($row['reservation_status'], ['confirmed', 'completed', 'no_show'], true) : $row['ledger_status'] !== 'verified')) $kinds[] = 'fulfillment_failed';
        return array_values(array_unique($kinds));
    }

    private function attachHistory(array &$cases): void
    {
        if (!$cases) return;
        $keys = array_column($cases, 'key');
        $find = Database::connection()->prepare('SELECT a.case_key,a.action,a.fingerprint,a.note,a.created_at,u.name AS actor_name FROM reconciliation_actions a JOIN users u ON u.id=a.actor_id WHERE a.case_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY a.id');
        $find->execute($keys);
        $history = [];
        foreach ($find->fetchAll() as $action) $history[$action['case_key']][] = $action;
        foreach ($cases as &$case) {
            $case['history'] = $history[$case['key']] ?? [];
            $case['reviewed'] = false;
            foreach ($case['history'] as $action) if ($action['action'] === 'reviewed') $case['reviewed'] = hash_equals($action['fingerprint'], $case['fingerprint']);
        }
    }

    public function find(string $key, int $facilityId = 0): array
    {
        if (!preg_match('/^(attempt|exception):[1-9]\d{0,17}$/D', $key)) throw new \InvalidArgumentException('Choose a valid reconciliation case.');
        foreach ($this->cases($facilityId, true) as $case) if ($case['key'] === $key) return $case;
        throw new \OutOfBoundsException('That reconciliation case is no longer open.');
    }

    public function act(int $adminId, string $action, string $key, string $note, string $expected = ''): string
    {
        if (!in_array($action, ['note', 'review', 'recheck'], true)) throw new \InvalidArgumentException('Unsupported reconciliation action. Payment status can only change through verified provider evidence.');
        if (!RateLimiter::consume('admin-reconciliation', (string) $adminId, 60, 900)) throw new \InvalidArgumentException('Too many reconciliation actions. Try again in 15 minutes.');
        if ($action === 'recheck') {
            if (!preg_match('/^attempt:([1-9]\d{0,17})$/D', $key, $match)) throw new \InvalidArgumentException('Only payment cases can be rechecked with Maya.');
            return $this->recheck($adminId, (int) $match[1]);
        }
        $case = $this->find($key);
        $note = Reason::require($note);
        if ($action === 'review' && !hash_equals($case['fingerprint'], $expected)) throw new \InvalidArgumentException('This case changed since you opened it. Review the current records before marking it reviewed.');
        $this->append($adminId, $key, $case['source'] === 'payment' ? (int) $case['id'] : null, $case['source'] === 'exception' ? (int) $case['id'] : null, $action === 'review' ? 'reviewed' : 'note', $case['fingerprint'], $note);
        AdminAudit::record(null, $adminId, $action === 'review' ? 'reconciliation.reviewed' : 'reconciliation.note_added', $case['source'] === 'payment' ? 'payment_attempt' : 'session_exception',
            (int) $case['id'], (int) $case['facility_id'], $note, ['kinds' => $case['kinds'], 'local_status' => $case['local_status']], ['reviewed' => $action === 'review']);
        return $action === 'review' ? 'Case marked reviewed. It reopens automatically if the payment records change.' : 'Investigation note recorded.';
    }

    /** Provider recheck of any payment by reference, so a contradiction of an already verified payment can be found. */
    public function recheckReference(int $adminId, string $reference): string
    {
        if (!preg_match('/^PNGO-[A-F0-9]{24}$/D', $reference)) throw new \InvalidArgumentException('Enter a valid payment reference (PNGO-…).');
        if (!RateLimiter::consume('admin-reconciliation', (string) $adminId, 60, 900)) throw new \InvalidArgumentException('Too many reconciliation actions. Try again in 15 minutes.');
        $find = Database::connection()->prepare('SELECT id FROM payment_attempts WHERE reference=?'); $find->execute([$reference]); $id = $find->fetchColumn();
        if (!$id) throw new \OutOfBoundsException('Payment not found.');
        return $this->recheck($adminId, (int) $id);
    }

    private function recheck(int $adminId, int $attemptId): string
    {
        $state = function () use ($attemptId): array {
            $find = Database::connection()->prepare('SELECT pa.id,pa.local_status,pa.provider_status,pa.reconciliation_reason,pa.maya_checkout_id,af.id AS facility_id FROM payment_attempts pa ' . OperationsMonitor::ATTEMPT_FACILITY . ' WHERE pa.id=?');
            $find->execute([$attemptId]);
            return $find->fetch() ?: throw new \OutOfBoundsException('Payment not found.');
        };
        $before = $state();
        if (!$before['maya_checkout_id']) throw new \InvalidArgumentException('This payment has no Maya checkout reference to recheck.');
        try { $result = (new PaymentService())->recheckAttempt($attemptId); }
        catch (\RuntimeException $error) {
            if ($error instanceof \PDOException || $error instanceof \OutOfBoundsException || $error instanceof \InvalidArgumentException) throw $error;
            $result = ['outcome' => 'provider_unavailable', 'provider_status' => null, 'message' => 'Maya checks are unavailable (provider disabled or not configured). Nothing was changed.'];
        }
        $after = $state(); $key = 'attempt:' . $attemptId;
        $case = null; foreach ($this->cases(0, true) as $candidate) if ($candidate['key'] === $key) $case = $candidate;
        $this->append($adminId, $key, $attemptId, null, 'recheck', $case['fingerprint'] ?? hash('sha256', 'not-queued'), $result['message']);
        AdminAudit::record(null, $adminId, 'reconciliation.rechecked', 'payment_attempt', $attemptId, (int) $before['facility_id'], null,
            ['local_status' => $before['local_status'], 'provider_status' => $before['provider_status']], ['local_status' => $after['local_status'], 'provider_status' => $after['provider_status'], 'outcome' => $result['outcome']]);
        return $result['message'];
    }

    private function append(int $adminId, string $key, ?int $attemptId, ?int $exceptionId, string $action, string $fingerprint, string $note): void
    {
        Database::connection()->prepare('INSERT INTO reconciliation_actions (case_key,payment_attempt_id,session_exception_id,action,fingerprint,note,actor_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$key, $attemptId, $exceptionId, $action, $fingerprint, mb_substr($note, 0, 1000), $adminId]);
    }
}
