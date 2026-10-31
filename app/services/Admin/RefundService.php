<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\{Clock, Database, RateLimiter};
use ParkNGo\Services\Payments\{MayaApiException, MayaClient};

/**
 * Controlled refund lifecycle. Approval is not proof that money was returned:
 *   requested -> under_review -> approved | rejected
 *   approved -> provider_submitted (admin records the Maya refund id created through the Maya Manager)
 *   provider_submitted -> provider_confirmed | provider_failed | reconciliation (only from retrieved Maya evidence)
 * Park n Go never submits provider refunds. Amounts reserved by approved or in-flight refunds count against
 * the refundable balance, so the total can never exceed the verified payment.
 */
final class RefundService
{
    public const OPEN = ['requested', 'under_review', 'approved', 'provider_submitted'];
    /** Statuses that hold part of the payment's refundable balance. */
    public const RESERVING = ['approved', 'provider_submitted', 'provider_confirmed', 'reconciliation'];
    public const LABELS = [
        'requested' => 'Requested', 'under_review' => 'Under review', 'approved' => 'Approved — provider refund pending', 'rejected' => 'Rejected',
        'provider_submitted' => 'Provider refund submitted — awaiting confirmation', 'provider_confirmed' => 'Refund confirmed by Maya',
        'provider_failed' => 'Provider refund failed', 'reconciliation' => 'Provider evidence requires reconciliation',
    ];

    public function __construct(private readonly ?\Closure $refundRetriever = null)
    {
    }

    /** Verified payments only: locally verified, or provider-confirmed late payments held in reconciliation. */
    public function payment(string $reference, ?\PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $find = $pdo->prepare("SELECT pa.*,af.id AS facility_id,af.name AS facility_name,ar.reference AS reservation_reference FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE pa.reference=?");
        $find->execute([$reference]); $payment = $find->fetch();
        if (!$payment) throw new \OutOfBoundsException('Payment not found.');
        $verified = $payment['local_status'] === 'verified' || ($payment['local_status'] === 'reconciliation' && in_array($payment['provider_status'], ['PAYMENT_SUCCESS', 'CAPTURED'], true));
        if (!$verified || !$payment['maya_checkout_id']) throw new \InvalidArgumentException('Refunds can only be requested for a provider-verified payment.');
        $payment['refunds'] = $this->forPayment((int) $payment['id'], $pdo);
        $reserved = array_sum(array_map(static fn (array $r): int => in_array($r['status'], self::RESERVING, true) ? (int) $r['amount_centavos'] : 0, $payment['refunds']));
        $payment['refundable_centavos'] = max(0, (int) $payment['amount_centavos'] - $reserved);
        return $payment;
    }

    private function forPayment(int $attemptId, \PDO $pdo, ?int $except = null): array
    {
        $find = $pdo->prepare('SELECT * FROM refund_requests WHERE payment_attempt_id=? AND id<>? ORDER BY id'); $find->execute([$attemptId, $except ?? 0]);
        return $find->fetchAll();
    }

    public function request(int $adminId, string $paymentReference, string $amount, string $reason, string $key): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new \InvalidArgumentException('This refund form is invalid. Open a fresh form.');
        if (!preg_match('/^PNGO-[A-F0-9]{24}$/D', $paymentReference)) throw new \InvalidArgumentException('Enter a valid payment reference.');
        $centavos = Reason::centavos($amount, 'refund amount'); $reason = Reason::require($reason);
        if (!RateLimiter::consume('admin-refund-request', (string) $adminId, 30, 900)) throw new \InvalidArgumentException('Too many refund requests. Try again in 15 minutes.');
        self::readCommitted();
        return Database::transaction(function (\PDO $pdo) use ($adminId, $paymentReference, $centavos, $reason, $key): array {
            // The payment row alone is the serialization point for every refund of that payment.
            $pdo->prepare('SELECT id FROM payment_attempts WHERE reference=? FOR UPDATE')->execute([$paymentReference]);
            $payment = $this->payment($paymentReference, $pdo);
            $existing = $pdo->prepare('SELECT * FROM refund_requests WHERE idempotency_key=?'); $existing->execute([$key]);
            if ($row = $existing->fetch()) {
                if ((int) $row['payment_attempt_id'] !== (int) $payment['id'] || (int) $row['amount_centavos'] !== $centavos) throw new \InvalidArgumentException('This refund form was already used for a different request.');
                return $row + ['duplicate' => true];
            }
            foreach ($payment['refunds'] as $refund) if (in_array($refund['status'], self::OPEN, true)) throw new \InvalidArgumentException('Refund ' . $refund['reference'] . ' is still open for this payment. Finish or reject it first.');
            if ($centavos > $payment['refundable_centavos']) throw new \InvalidArgumentException('The refund exceeds the remaining refundable balance of ₱' . number_format($payment['refundable_centavos'] / 100, 2) . '.');
            $reference = 'RFD-' . strtoupper(bin2hex(random_bytes(10)));
            $pdo->prepare('INSERT INTO refund_requests (reference,payment_attempt_id,amount_centavos,request_reason,requested_by,idempotency_key) VALUES (?,?,?,?,?,?)')->execute([$reference, $payment['id'], $centavos, $reason, $adminId, $key]);
            $id = (int) $pdo->lastInsertId();
            AdminAudit::record($pdo, $adminId, 'refund.requested', 'refund_request', $id, (int) $payment['facility_id'], $reason, ['payment' => $payment['reference'], 'refundable_centavos' => $payment['refundable_centavos']],
                ['reference' => $reference, 'status' => 'requested', 'amount_centavos' => $centavos]);
            return $this->get($id, $pdo) + ['duplicate' => false];
        });
    }

    public function get(int $id, ?\PDO $pdo = null): array
    {
        $find = ($pdo ?? Database::connection())->prepare('SELECT rr.*,pa.reference AS payment_reference,pa.amount_centavos AS payment_centavos,pa.payment_type,pa.maya_checkout_id,af.id AS facility_id,af.name AS facility_name
            FROM refund_requests rr JOIN payment_attempts pa ON pa.id=rr.payment_attempt_id ' . OperationsMonitor::ATTEMPT_FACILITY . ' WHERE rr.id=?');
        $find->execute([$id]);
        return $find->fetch() ?: throw new \OutOfBoundsException('Refund request not found.');
    }

    public function list(int $facilityId, string $status, int $page): array
    {
        $statuses = $status === 'open' ? self::OPEN : ($status === 'all' ? array_keys(self::LABELS) : [$status]);
        if (array_diff($statuses, array_keys(self::LABELS))) throw new \InvalidArgumentException('Choose a valid refund status.');
        $where = 'rr.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ') AND (?=0 OR af.id=?)';
        $params = [...$statuses, $facilityId, $facilityId];
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM refund_requests rr JOIN payment_attempts pa ON pa.id=rr.payment_attempt_id ' . OperationsMonitor::ATTEMPT_FACILITY . " WHERE $where");
        $count->execute($params);
        $find = Database::connection()->prepare('SELECT rr.*,pa.reference AS payment_reference,pa.amount_centavos AS payment_centavos,pa.payment_type,af.name AS facility_name,req.name AS requested_by_name,decider.name AS decided_by_name
            FROM refund_requests rr JOIN payment_attempts pa ON pa.id=rr.payment_attempt_id ' . OperationsMonitor::ATTEMPT_FACILITY . " JOIN users req ON req.id=rr.requested_by LEFT JOIN users decider ON decider.id=rr.decided_by
            WHERE $where ORDER BY rr.id DESC LIMIT 25 OFFSET " . (max(1, $page) - 1) * 25);
        $find->execute($params);
        return ['rows' => $find->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** All refund decisions and provider evidence require approve_refunds. Lock order: payment, then refund. */
    private function transition(int $adminId, int $id, callable $change): array
    {
        self::readCommitted();
        return Database::transaction(function (\PDO $pdo) use ($adminId, $id, $change): array {
            AdminPermissions::authorize($pdo, $adminId, 'approve_refunds');
            $peek = $pdo->prepare('SELECT payment_attempt_id FROM refund_requests WHERE id=?'); $peek->execute([$id]); $attemptId = $peek->fetchColumn();
            if (!$attemptId) throw new \OutOfBoundsException('Refund request not found.');
            $pdo->prepare('SELECT id FROM payment_attempts WHERE id=? FOR UPDATE')->execute([$attemptId]);
            $lock = $pdo->prepare('SELECT * FROM refund_requests WHERE id=? FOR UPDATE'); $lock->execute([$id]); $before = $lock->fetch();
            $refund = $this->get($id, $pdo);
            [$action, $reason, $after] = $change($pdo, $refund);
            AdminAudit::record($pdo, $adminId, $action, 'refund_request', $id, (int) $refund['facility_id'], $reason,
                ['status' => $before['status'], 'amount_centavos' => (int) $before['amount_centavos'], 'provider_refund_id' => $before['provider_refund_id']], $after);
            return $this->get($id, $pdo);
        });
    }

    public function startReview(int $adminId, int $id): array
    {
        return $this->transition($adminId, $id, function (\PDO $pdo, array $refund) use ($adminId): array {
            if ($refund['status'] !== 'requested') throw new \InvalidArgumentException('Only a new request can be moved to review.');
            $pdo->prepare("UPDATE refund_requests SET status='under_review',review_started_by=?,review_started_at=UTC_TIMESTAMP() WHERE id=? AND status='requested'")->execute([$adminId, $refund['id']]);
            return ['refund.review_started', null, ['status' => 'under_review']];
        });
    }

    public function decide(int $adminId, int $id, bool $approve, string $reason): array
    {
        $reason = Reason::require($reason);
        return $this->transition($adminId, $id, function (\PDO $pdo, array $refund) use ($adminId, $approve, $reason): array {
            if (!in_array($refund['status'], ['requested', 'under_review'], true)) throw new \InvalidArgumentException('This refund has already been decided.');
            if ($approve) {
                $reserved = 0;
                foreach ($this->forPayment((int) $refund['payment_attempt_id'], $pdo, (int) $refund['id']) as $other) if (in_array($other['status'], self::RESERVING, true)) $reserved += (int) $other['amount_centavos'];
                if ($reserved + (int) $refund['amount_centavos'] > (int) $refund['payment_centavos']) throw new \InvalidArgumentException('Approving this refund would exceed the verified payment amount.');
            }
            $status = $approve ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE refund_requests SET status=?,decided_by=?,decided_at=UTC_TIMESTAMP(),decision_reason=? WHERE id=? AND status IN (\'requested\',\'under_review\')')->execute([$status, $adminId, $reason, $refund['id']]);
            return ['refund.' . $status, $reason, ['status' => $status]];
        });
    }

    /** Plain reads inside refund transactions must see rows committed before our locks were granted. */
    private static function readCommitted(): void
    {
        Database::connection()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function recordProviderRefund(int $adminId, int $id, string $providerRefundId): array
    {
        $providerRefundId = trim($providerRefundId);
        if (!preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $providerRefundId)) throw new \InvalidArgumentException('Enter the Maya refund identifier exactly as shown in Maya Manager.');
        try {
            return $this->transition($adminId, $id, function (\PDO $pdo, array $refund) use ($adminId, $providerRefundId): array {
                if ($refund['status'] !== 'approved') throw new \InvalidArgumentException('Record a provider refund only for an approved request.');
                $pdo->prepare("UPDATE refund_requests SET status='provider_submitted',provider_refund_id=?,provider_recorded_by=?,provider_submitted_at=UTC_TIMESTAMP() WHERE id=? AND status='approved'")->execute([$providerRefundId, $adminId, $refund['id']]);
                return ['refund.provider_reference_recorded', null, ['status' => 'provider_submitted', 'provider_refund_id' => $providerRefundId]];
            });
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new \InvalidArgumentException('That Maya refund is already linked to another refund request.');
            throw $error;
        }
    }

    /** Retrieves the refund from Maya. Only matching provider success confirms it; repeated checks are idempotent. */
    public function checkProvider(int $adminId, int $id): array
    {
        $refund = $this->get($id);
        if ($refund['status'] === 'provider_confirmed') return $refund + ['message' => 'Already confirmed by Maya. No change.'];
        if ($refund['status'] !== 'provider_submitted') throw new \InvalidArgumentException('Only a submitted provider refund can be checked.');
        if (!AdminPermissions::has($adminId, 'approve_refunds')) throw new PermissionDenied('approve_refunds');
        try {
            $provider = $this->refundRetriever ? ($this->refundRetriever)((string) $refund['maya_checkout_id'], (string) $refund['provider_refund_id'])
                : (new MayaClient())->retrieveRefund((string) $refund['maya_checkout_id'], (string) $refund['provider_refund_id']);
        } catch (MayaApiException $error) {
            return $refund + ['message' => 'Maya was unavailable (HTTP ' . $error->httpStatus . '). The refund is still awaiting confirmation.'];
        } catch (\RuntimeException $error) {
            if ($error instanceof \PDOException) throw $error;
            return $refund + ['message' => 'Maya checks are unavailable (provider disabled or not configured). The refund is still awaiting confirmation.'];
        }
        [$state, $providerStatus, $note] = $this->interpret($provider, $refund);
        $updated = $this->transition($adminId, $id, function (\PDO $pdo, array $current) use ($state, $providerStatus, $note): array {
            if ($current['status'] !== 'provider_submitted') return ['refund.provider_checked', null, ['status' => $current['status'], 'unchanged' => true]];
            $now = Clock::now()->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE refund_requests SET status=?,provider_status=?,provider_checked_at=?,provider_confirmed_at=IF(?=\'provider_confirmed\',?,provider_confirmed_at) WHERE id=? AND status=\'provider_submitted\'')
                ->execute([$state, $providerStatus, $now, $state, $now, $current['id']]);
            return ['refund.provider_checked', $note, ['status' => $state, 'provider_status' => $providerStatus]];
        });
        return $updated + ['message' => $note];
    }

    /** Maya refund field names must be confirmed against live Sandbox evidence before production use. */
    private function interpret(\stdClass $provider, array $refund): array
    {
        $id = is_string($provider->id ?? null) ? $provider->id : '';
        $status = strtoupper(is_string($provider->status ?? null) ? $provider->status : '');
        $amount = isset($provider->amount) && is_object($provider->amount) ? ($provider->amount->value ?? null) : ($provider->amount ?? ($provider->totalAmount->value ?? null));
        $currency = isset($provider->amount) && is_object($provider->amount) ? ($provider->amount->currency ?? null) : ($provider->currency ?? 'PHP');
        $paymentId = is_string($provider->paymentId ?? null) ? $provider->paymentId : (is_string($provider->payment ?? null) ? $provider->payment : '');
        if ($id === '' || !hash_equals((string) $refund['provider_refund_id'], $id) || ($paymentId !== '' && !hash_equals((string) $refund['maya_checkout_id'], $paymentId))) {
            return ['reconciliation', $status ?: 'UNKNOWN', 'Maya returned a refund that does not match this request.'];
        }
        if (!is_numeric($amount) || strtoupper((string) $currency) !== 'PHP' || (int) round((float) $amount * 100) !== (int) $refund['amount_centavos']) {
            return ['reconciliation', $status ?: 'UNKNOWN', 'Maya reported a refund amount or currency that differs from the approved request.'];
        }
        return match ($status) {
            'SUCCESS', 'REFUNDED', 'COMPLETED' => ['provider_confirmed', $status, 'Maya confirms the refund was completed.'],
            'FAILED', 'DECLINED', 'CANCELLED' => ['provider_failed', $status, 'Maya reports the refund ' . strtolower($status) . '. The amount is no longer reserved.'],
            default => ['provider_submitted', $status ?: 'UNKNOWN', 'Maya reports ' . ($status ?: 'no status') . '; the refund is still awaiting confirmation.'],
        };
    }
}
