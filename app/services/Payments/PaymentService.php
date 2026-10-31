<?php
declare(strict_types=1);
namespace ParkNGo\Services\Payments;

use ParkNGo\Services\{Audit, Clock, Database, Environment};

final class PaymentService
{
    public function __construct(private readonly ?\Closure $paymentRetriever = null)
    {
    }

    public function handleVerifiedWebhook(array $payload): void
    {
        $this->fulfillVerifiedPayment($payload);
    }

    private function fulfillVerifiedPayment(array $payload, ?string $eventId = null): void
    {
        $status = is_string($payload['paymentStatus'] ?? null) ? $payload['paymentStatus'] : (is_string($payload['status'] ?? null) ? $payload['status'] : '');
        $providerId = is_string($payload['id'] ?? null) ? $payload['id'] : '';
        $reference = is_string($payload['requestReferenceNumber'] ?? null) ? $payload['requestReferenceNumber'] : '';
        $amount = $payload['totalAmount']['value'] ?? ($payload['amount'] ?? null);
        $currency = $payload['totalAmount']['currency'] ?? ($payload['currency'] ?? null);
        if ($providerId === '' || $reference === '' || !is_string($currency) || strtoupper($currency) !== 'PHP' || !is_numeric($amount)) throw new \InvalidArgumentException('Incomplete Maya payment notification.');
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        Database::transaction(function (\PDO $pdo) use ($status, $providerId, $reference, $amount, $currency, $hash, $eventId): void {
            // Lock order matches checkout creation and exit: parking session first, then the payment attempt.
            $peek = $pdo->prepare('SELECT parking_session_id FROM payment_attempts WHERE reference=?'); $peek->execute([$reference]); $sessionId = $peek->fetchColumn();
            if ($sessionId) $pdo->prepare('SELECT id FROM parking_sessions WHERE id=? FOR UPDATE')->execute([(int) $sessionId]);
            $find = $pdo->prepare('SELECT * FROM payment_attempts WHERE reference=? FOR UPDATE'); $find->execute([$reference]); $attempt = $find->fetch();
            if (!$attempt) throw new \InvalidArgumentException('Unknown payment reference.');
            $cents = (int) round((float) $amount * 100);
            if ($cents !== (int) $attempt['amount_centavos'] || strtoupper($currency) !== $attempt['currency']) throw new \InvalidArgumentException('Maya payment amount or currency does not match.');
            try { $pdo->prepare('INSERT INTO payment_attempt_events (payment_attempt_id,provider_event_id,provider_status,payload_hash) VALUES (?,?,?,?)')->execute([$attempt['id'], $eventId ?? $providerId, $status, $hash]); }
            catch (\PDOException $error) { if ((int) ($error->errorInfo[1] ?? 0) === 1062) return; throw $error; }
            $finalSuccess = $status === 'PAYMENT_SUCCESS' || $status === 'CAPTURED';
            if (!$finalSuccess) {
                $local = in_array($status, ['PAYMENT_EXPIRED','PAYMENT_CANCELLED','PAYMENT_FAILED'], true) ? strtolower(str_replace('PAYMENT_', '', $status)) : 'pending_verification';
                // Locally final attempts (for example a swept checkout) record the provider status but never return to pending.
                $pdo->prepare('UPDATE payment_attempts SET provider_status=?, failure_reason=IF(? IN (\'failed\',\'expired\',\'cancelled\'),?,failure_reason), local_status=IF(local_status IN (\'created\',\'checkout_created\',\'pending_verification\'),?,local_status) WHERE id=? AND local_status NOT IN (\'verified\',\'reconciliation\')')->execute([$status, $local, 'Maya reported ' . $status . '.', $local, $attempt['id']]);
                if (in_array($local, ['failed','expired','cancelled'], true)) {
                    $ledger = $attempt['payment_type'] === 'PARKING_EXTENSION' ? 'parking_extensions' : ($attempt['payment_type'] === 'OVERTIME_SETTLEMENT' ? 'parking_overtime_settlements' : null);
                    if ($ledger) $pdo->prepare("UPDATE {$ledger} SET status=? WHERE payment_attempt_id=? AND status='pending'")->execute([$local, $attempt['id']]);
                }
                return;
            }
            if ($attempt['local_status'] === 'verified') {
                // Webhook and polling both report the same payment; never re-apply it or renew grace.
                if ($attempt['maya_checkout_id'] !== null && !hash_equals((string) $attempt['maya_checkout_id'], $providerId)) {
                    $pdo->prepare('UPDATE payment_attempts SET reconciliation_reason=? WHERE id=?')->execute(['Another provider payment was reported for this already verified attempt.', $attempt['id']]);
                    Audit::record('payment.duplicate_provider_payment_review', (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt');
                }
                return;
            }
            if (in_array($attempt['local_status'], ['failed','expired','cancelled','reconciliation'], true)) {
                $this->reconcile($pdo, $attempt, $status, 'Provider confirmed payment after the checkout was no longer fulfillable.', 'payment.late_confirmation_reconciliation_required');
                return;
            }
            if ($attempt['payment_type'] === 'OVERTIME_SETTLEMENT') {
                $settlement = $pdo->prepare('SELECT * FROM parking_overtime_settlements WHERE payment_attempt_id=? FOR UPDATE');
                $settlement->execute([(int) $attempt['id']]); $settlement = $settlement->fetch();
                if (!$settlement) throw new \InvalidArgumentException('Overtime settlement record not found.');
                $session = $pdo->prepare('SELECT * FROM parking_sessions WHERE id=?'); $session->execute([(int) $settlement['parking_session_id']]); $session = $session->fetch();
                $covered = $pdo->prepare("SELECT 1 FROM parking_overtime_settlements WHERE parking_session_id=? AND id<>? AND status='verified' AND interval_end>? LIMIT 1");
                $covered->execute([(int) $settlement['parking_session_id'], (int) $settlement['id'], $settlement['interval_start']]);
                // An extension or another settlement already covering this interval would bill it twice.
                if ($settlement['status'] !== 'pending' || !$session || $session['status'] !== 'active' || $session['exit_at'] !== null || $session['paid_until'] > $settlement['interval_start'] || $covered->fetchColumn()) {
                    $this->reconcile($pdo, $attempt, $status, 'Payment completed but the overtime interval was already covered or the session is no longer active.', 'payment.overtime_reconciliation_required');
                    return;
                }
                $pdo->prepare("UPDATE payment_attempts SET provider_status=?,local_status='verified',provider_completed_at=UTC_TIMESTAMP(),verified_at=UTC_TIMESTAMP() WHERE id=? AND local_status<>'verified'")->execute([$status, $attempt['id']]);
                // Grace starts at the server verification time, on the same clock the exit check uses.
                $verifiedAt = Clock::now();
                $pdo->prepare("UPDATE parking_overtime_settlements SET status='verified',verified_at=?,grace_until=? WHERE id=? AND status='pending'")->execute([$verifiedAt->format('Y-m-d H:i:s'), $verifiedAt->modify('+15 minutes')->format('Y-m-d H:i:s'), $settlement['id']]);
                Audit::record('payment.verified_overtime_settlement', (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt');
                return;
            }
            if ($attempt['payment_type'] === 'PARKING_EXTENSION') {
                $extension = $pdo->prepare('SELECT * FROM parking_extensions WHERE payment_attempt_id=? FOR UPDATE');
                $extension->execute([(int) $attempt['id']]); $extension = $extension->fetch();
                if (!$extension) throw new \InvalidArgumentException('Extension record not found.');
                $session = $pdo->prepare("SELECT p.*,r.slot_id FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id WHERE p.id=? AND p.status='active' AND p.exit_at IS NULL");
                $session->execute([(int) $extension['parking_session_id']]); $session = $session->fetch();
                $base = (new \DateTimeImmutable($extension['requested_paid_until'], new \DateTimeZone('UTC')))->modify('-' . (int) $extension['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s');
                $covered = $pdo->prepare("SELECT 1 FROM parking_overtime_settlements WHERE parking_session_id=? AND status='verified' AND interval_end>? LIMIT 1");
                $covered->execute([(int) $extension['parking_session_id'], $base]);
                if ($extension['status'] !== 'pending' || !$session || $session['paid_until'] !== $extension['paid_until_before'] || $covered->fetchColumn()
                    || $this->extensionConflict($pdo, (int) $session['slot_id'], (int) $session['reservation_id'], $base, $extension['requested_paid_until']) !== null) {
                    $this->reconcile($pdo, $attempt, $status, 'Payment completed but the parking session extension could not be applied safely.', 'payment.extension_reconciliation_required');
                    return;
                }
                $pdo->prepare("UPDATE payment_attempts SET provider_status=?,local_status='verified',provider_completed_at=UTC_TIMESTAMP(),verified_at=UTC_TIMESTAMP() WHERE id=? AND local_status<>'verified'")->execute([$status, $attempt['id']]);
                $pdo->prepare("UPDATE parking_extensions SET status='verified',verified_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'")->execute([$extension['id']]);
                $pdo->prepare('UPDATE parking_sessions SET paid_until=? WHERE id=? AND status=\'active\'')->execute([$extension['requested_paid_until'], $extension['parking_session_id']]);
                Audit::record('payment.verified_session_extension', (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt');
                return;
            }
            $reservation = null;
            if ($attempt['reservation_id']) {
                $find = $pdo->prepare('SELECT r.*,h.expires_at AS hold_expires_at FROM reservations r LEFT JOIN reservation_holds h ON h.reservation_id=r.id AND h.released_at IS NULL WHERE r.id=? FOR UPDATE'); $find->execute([$attempt['reservation_id']]); $reservation = $find->fetch();
            }
            if (!$reservation || $reservation['status'] !== 'pending' || !$reservation['hold_expires_at'] || $reservation['hold_expires_at'] <= gmdate('Y-m-d H:i:s')) {
                $pdo->prepare("UPDATE payment_attempts SET provider_status=?,local_status='reconciliation',reconciliation_reason=? WHERE id=? AND local_status<>'verified'")->execute([$status, 'Payment completed but the Park n Go hold is no longer deliverable.', $attempt['id']]);
                Audit::record('payment.reconciliation_required', (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt'); return;
            }
            $pdo->prepare("UPDATE payment_attempts SET provider_status=?,local_status='verified',provider_completed_at=UTC_TIMESTAMP(),verified_at=UTC_TIMESTAMP() WHERE id=? AND local_status<>'verified'")->execute([$status, $attempt['id']]);
            $pdo->prepare("UPDATE reservations SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'")->execute([$reservation['id']]);
            Audit::record('payment.verified_reservation_confirmed', (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt');
        });
    }

    /**
     * Verify the authoritative provider state for a driver's own initial payment.
     * Browser redirect parameters are intentionally ignored.
     */
    public function verifyInitialPayment(int $userId, string $reference): array
    {
        $find = Database::connection()->prepare("SELECT * FROM payment_attempts WHERE user_id=? AND reference=? AND payment_type='INITIAL_RESERVATION'");
        $find->execute([$userId, $reference]); $attempt = $find->fetch();
        if (!$attempt) throw new \OutOfBoundsException('Payment attempt not found.');
        if (in_array($attempt['local_status'], ['verified', 'reconciliation', 'failed', 'expired', 'cancelled'], true) || !$attempt['maya_checkout_id']) return $attempt;
        try {
            $provider = $this->paymentRetriever
                ? ($this->paymentRetriever)((string) $attempt['maya_checkout_id'])
                : (new MayaClient())->retrievePayment((string) $attempt['maya_checkout_id']);
            $payload = $this->normaliseRetrievedPayment($provider, $attempt);
            $eventId = 'poll-' . $attempt['maya_checkout_id'] . '-' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->fulfillVerifiedPayment($payload, $eventId);
        } catch (MayaApiException $error) {
            error_log('Maya payment verification HTTP ' . $error->httpStatus . ' for payment attempt ' . (int) $attempt['id']);
            $this->markVerificationPending((int) $attempt['id'], 'Maya verification unavailable (HTTP ' . $error->httpStatus . ').');
        } catch (\InvalidArgumentException|\JsonException $error) {
            error_log('Maya payment verification rejected for payment attempt ' . (int) $attempt['id'] . ': ' . $error->getMessage());
            $this->markVerificationPending((int) $attempt['id'], 'Maya verification requires review.');
        }
        return $this->attempt($userId, $reference);
    }

    public function verifyPayment(int $userId, string $reference): array
    {
        $attempt = $this->attempt($userId, $reference);
        // A locally swept checkout may still have been paid at Maya; asking once routes a late success to reconciliation.
        $locallyExpired = $attempt['local_status'] === 'expired' && $attempt['failure_reason'] === 'Checkout expired.';
        if ((!$locallyExpired && in_array($attempt['local_status'], ['verified','reconciliation','failed','expired','cancelled'], true)) || !$attempt['maya_checkout_id']) return $attempt;
        try {
            $provider = $this->paymentRetriever ? ($this->paymentRetriever)((string)$attempt['maya_checkout_id']) : (new MayaClient())->retrievePayment((string)$attempt['maya_checkout_id']);
            $payload = $this->normaliseRetrievedPayment($provider, $attempt);
            $this->fulfillVerifiedPayment($payload, 'poll-' . $attempt['maya_checkout_id'] . '-' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        } catch (MayaApiException $error) {
            $this->markVerificationPending((int)$attempt['id'], 'Maya verification unavailable (HTTP ' . $error->httpStatus . ').');
        } catch (\InvalidArgumentException|\JsonException $error) {
            $this->markVerificationPending((int)$attempt['id'], 'Maya verification requires review.');
        }
        return $this->attempt($userId, $reference);
    }

    /**
     * Admin reconciliation recheck through the same server-side retrieval and fulfilment path as polling.
     * It never marks anything paid on its own: only a matching provider success reaches fulfilment, and a
     * provider report that contradicts a locally verified payment is flagged for review, not applied.
     */
    public function recheckAttempt(int $attemptId): array
    {
        $find = Database::connection()->prepare('SELECT * FROM payment_attempts WHERE id=?'); $find->execute([$attemptId]); $attempt = $find->fetch();
        if (!$attempt) throw new \OutOfBoundsException('Payment attempt not found.');
        if (!$attempt['maya_checkout_id']) throw new \InvalidArgumentException('This payment has no Maya checkout reference to recheck.');
        $flag = function (string $reason) use ($attempt): void {
            $update = Database::connection()->prepare("UPDATE payment_attempts SET reconciliation_reason=? WHERE id=? AND local_status IN ('verified','reconciliation') AND (reconciliation_reason IS NULL OR reconciliation_reason<>?)");
            $update->execute([$reason, $attempt['id'], $reason]);
            if ($update->rowCount()) Audit::record('payment.provider_local_mismatch', (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt');
        };
        try {
            $provider = $this->paymentRetriever ? ($this->paymentRetriever)((string) $attempt['maya_checkout_id']) : (new MayaClient())->retrievePayment((string) $attempt['maya_checkout_id']);
            $payload = $this->normaliseRetrievedPayment($provider, $attempt);
        } catch (MayaApiException $error) {
            return ['outcome' => 'provider_unavailable', 'provider_status' => null, 'message' => 'Maya was unavailable (HTTP ' . $error->httpStatus . '). Nothing was changed.'];
        } catch (\InvalidArgumentException|\JsonException $error) {
            if (in_array($attempt['local_status'], ['verified', 'reconciliation'], true)) $flag('Provider/local mismatch: ' . $error->getMessage());
            else $this->markVerificationPending((int) $attempt['id'], 'Maya verification requires review.');
            return ['outcome' => 'mismatch', 'provider_status' => null, 'message' => $error->getMessage()];
        }
        $status = $payload['paymentStatus']; $success = $status === 'PAYMENT_SUCCESS' || $status === 'CAPTURED';
        if ($attempt['local_status'] === 'verified' || $attempt['local_status'] === 'reconciliation') {
            // Already settled locally: record contradictions, never re-run fulfilment or reverse evidence.
            if (!$success) $flag('Provider/local mismatch: Maya now reports ' . $status . ' for a payment recorded as ' . $attempt['local_status'] . '.');
            return ['outcome' => $success ? 'consistent' : 'mismatch', 'provider_status' => $status, 'message' => $success ? 'Maya confirms the recorded payment.' : 'Maya reports ' . $status . '; flagged for review.'];
        }
        $this->fulfillVerifiedPayment($payload, 'poll-' . $attempt['maya_checkout_id'] . '-' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        $find->execute([$attemptId]); $updated = $find->fetch();
        return ['outcome' => 'checked', 'provider_status' => $status, 'message' => 'Maya reports ' . $status . '; local status is now ' . $updated['local_status'] . '.'];
    }

    private function markVerificationPending(int $attemptId, string $reason): void
    {
        Database::connection()->prepare("UPDATE payment_attempts SET local_status='pending_verification',failure_reason=? WHERE id=? AND local_status IN ('created','checkout_created','pending_verification')")->execute([$reason, $attemptId]);
    }

    private function normaliseRetrievedPayment(\stdClass $provider, array $attempt): array
    {
        $providerId = $this->stringProperty($provider, ['id', 'paymentId', 'checkoutId']);
        if ($providerId !== '' && !hash_equals((string) $attempt['maya_checkout_id'], $providerId)) {
            throw new \InvalidArgumentException('Maya payment identifier does not match the checkout attempt.');
        }
        $providerId = $providerId !== '' ? $providerId : (string) $attempt['maya_checkout_id'];
        $reference = $this->stringProperty($provider, ['requestReferenceNumber']);
        if ($reference === '' && isset($provider->metadata) && is_object($provider->metadata)) {
            $reference = $this->stringProperty($provider->metadata, ['parkngo_payment_reference']);
        }
        if ($reference === '' || ($reference !== $attempt['reference'] && $reference !== $attempt['maya_request_reference'])) {
            throw new \InvalidArgumentException('Maya payment reference does not match the payment attempt.');
        }
        $status = $this->stringProperty($provider, ['paymentStatus', 'status']);
        if ($status === '') throw new \InvalidArgumentException('Maya returned no payment status.');
        $amount = null; $currency = null;
        if (isset($provider->totalAmount) && is_object($provider->totalAmount)) {
            $amount = $provider->totalAmount->value ?? null; $currency = $provider->totalAmount->currency ?? null;
        }
        if ($amount === null) $amount = $provider->amount ?? null;
        if ($currency === null) $currency = $provider->currency ?? null;
        if (!is_numeric($amount) || !is_string($currency) || strtoupper($currency) !== 'PHP') throw new \InvalidArgumentException('Maya returned an invalid amount or currency.');
        if ((int) round((float) $amount * 100) !== (int) $attempt['amount_centavos']) throw new \InvalidArgumentException('Maya payment amount does not match the payment attempt.');
        foreach (['completedAt', 'paidAt', 'updatedAt'] as $field) {
            if (isset($provider->{$field}) && is_string($provider->{$field}) && strtotime($provider->{$field}) > time() + 300) throw new \InvalidArgumentException('Maya returned a future payment timestamp.');
        }
        return ['id' => $providerId, 'requestReferenceNumber' => $reference, 'paymentStatus' => $status, 'totalAmount' => ['value' => (string) $amount, 'currency' => strtoupper($currency)]];
    }

    private function stringProperty(\stdClass $object, array $names): string
    {
        foreach ($names as $name) if (isset($object->{$name}) && is_string($object->{$name})) return trim($object->{$name});
        return '';
    }

    public function verifyWebhookSignature(string $body, string $signature, string $timestamp): array
    {
        $config = require BASE_PATH . '/config/maya.php';
        if (!preg_match('/^\d{10,}$/D', $timestamp) || abs((int) $timestamp - (int) floor(microtime(true) * 1000)) > $config['webhook_tolerance_ms']) throw new \InvalidArgumentException('Stale Maya webhook.');
        $parts = []; foreach (explode(',', $signature) as $part) { $pair = explode('=', trim($part), 2); if (count($pair) === 2) $parts[$pair[0]] = $pair[1]; }
        if (!isset($parts['nonce'], $parts['v1']) || !ctype_xdigit($parts['v1'])) throw new \InvalidArgumentException('Invalid Maya webhook signature.');
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR); if (!is_array($payload)) throw new \InvalidArgumentException('Invalid Maya webhook payload.');
        $flat = [];
        $flatten = static function (mixed $value, string $prefix = '') use (&$flatten, &$flat): void {
            if (!is_array($value)) { if ($value !== null && $value !== '') $flat[] = $prefix . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value); return; }
            if ($value === []) return;
            foreach ($value as $key => $child) $flatten($child, $prefix === '' ? (string) $key : $prefix . '.' . $key);
        };
        $flatten($payload); sort($flat, SORT_STRING); $verify = implode('&', $flat) . '&nonce=' . $parts['nonce'];
        foreach ([$config['webhook_key_1'], $config['webhook_key_2']] as $key) if ($key !== '' && openssl_verify($verify, hex2bin($parts['v1']), $key, OPENSSL_ALGO_SHA256) === 1) return $payload;
        throw new \InvalidArgumentException('Maya webhook signature rejected.');
    }

    public function startInitialReservationPayment(int $userId, int $reservationId): string
    {
        $attempt = Database::transaction(function (\PDO $pdo) use ($userId, $reservationId): array {
            $find = $pdo->prepare('SELECT r.*, h.expires_at AS hold_expires_at FROM reservations r LEFT JOIN reservation_holds h ON h.reservation_id=r.id AND h.released_at IS NULL WHERE r.id=? AND r.user_id=? FOR UPDATE');
            $find->execute([$reservationId, $userId]); $reservation = $find->fetch();
            if (!$reservation) throw new \OutOfBoundsException('Reservation not found.');
            if ($reservation['status'] !== 'pending' || !$reservation['hold_expires_at'] || $reservation['hold_expires_at'] <= gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('Reservation expired. Please select an available slot and start again.');
            $find = $pdo->prepare("SELECT * FROM payment_attempts WHERE reservation_id=? AND payment_type='INITIAL_RESERVATION' AND local_status IN ('created','checkout_created','pending_verification') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $find->execute([$reservationId]); $existing = $find->fetch();
            if ($existing && $existing['maya_checkout_id']) return $existing;
            $reference = 'PNGO-' . strtoupper(bin2hex(random_bytes(12)));
            $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,reservation_id,payment_type,amount_centavos,currency,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,?,?,'PHP',?,'created',?)")
                ->execute([$reference, $userId, $reservationId, 'INITIAL_RESERVATION', (int) $reservation['estimated_centavos'], $reference, $reservation['hold_expires_at']]);
            $attemptId = (int) $pdo->lastInsertId();
            Audit::record('payment.checkout_started', $userId, $attemptId, 'payment_attempt');
            return ['id' => $attemptId, 'reference' => $reference, 'amount_centavos' => (int) $reservation['estimated_centavos'], 'reservation_id' => $reservationId];
        });
        if (!empty($attempt['maya_redirect_url'])) return $attempt['maya_redirect_url'];
        try {
            $client = new MayaClient();
            $returnBase = rtrim(Environment::get('APP_URL', 'http://localhost/park-n-go/public'), '/');
            $returnUrl = $returnBase . '/' . url('payment-return', ['reference' => $attempt['reference']]);
            $checkout = $client->createCheckout([
                'totalAmount' => ['value' => number_format($attempt['amount_centavos'] / 100, 2, '.', ''), 'currency' => 'PHP'],
                'buyer' => ['firstName' => 'Park n Go', 'lastName' => 'Driver'],
                'items' => [[
                    'name' => 'Parking reservation',
                    'quantity' => 1,
                    'amount' => ['value' => number_format($attempt['amount_centavos'] / 100, 2, '.', '')],
                    'totalAmount' => ['value' => number_format($attempt['amount_centavos'] / 100, 2, '.', '')],
                ]],
                'redirectUrl' => ['success' => $returnUrl, 'failure' => $returnUrl, 'cancel' => $returnUrl],
                'requestReferenceNumber' => $attempt['reference'],
                'metadata' => ['parkngo_payment_reference' => $attempt['reference']],
            ]);
            Database::connection()->prepare("UPDATE payment_attempts SET maya_checkout_id=?, maya_redirect_url=?, local_status='checkout_created' WHERE id=? AND local_status='created'")->execute([$checkout['checkout_id'], $checkout['redirect_url'], $attempt['id']]);
            return $checkout['redirect_url'];
        } catch (\Throwable $error) {
            Database::connection()->prepare("UPDATE payment_attempts SET local_status='failed', failure_reason=? WHERE id=? AND local_status='created'")->execute(['Checkout creation failed.', $attempt['id']]);
            throw $error;
        }
    }

    public function startOvertimeSettlement(int $userId, int $sessionId): string
    {
        $data = $this->createSessionPayment($userId, $sessionId, 'OVERTIME_SETTLEMENT', null);
        return $this->createCheckout($data, 'Overtime settlement');
    }

    public function startExtensionPayment(int $userId, int $sessionId, array $blocks): string
    {
        $allowed = [1, 2, 3];
        $hours = 0;
        foreach ($blocks as $block) {
            if (filter_var($block, FILTER_VALIDATE_INT) === false || !in_array((int)$block, $allowed, true)) throw new \InvalidArgumentException('Choose only one, two, or three hour extension blocks.');
            $hours += (int)$block;
        }
        if ($hours < 1 || $hours > 6) throw new \InvalidArgumentException('Choose extension blocks totaling no more than six hours.');
        return $this->createCheckout($this->createSessionPayment($userId, $sessionId, 'PARKING_EXTENSION', $hours), 'Parking extension');
    }

    private function createSessionPayment(int $userId, int $sessionId, string $type, ?int $hours): array
    {
        return Database::transaction(function (\PDO $pdo) use ($userId, $sessionId, $type, $hours): array {
            $find = $pdo->prepare("SELECT p.*,r.slot_id,rates.overtime_hour_centavos,rates.succeeding_hour_centavos FROM parking_sessions p JOIN reservations r ON r.id=p.reservation_id JOIN parking_rates rates ON rates.id=r.rate_id WHERE p.id=? AND r.user_id=? AND p.status='active' AND p.exit_at IS NULL FOR UPDATE");
            $find->execute([$sessionId, $userId]); $session = $find->fetch();
            if (!$session) throw new \OutOfBoundsException('Active parking session not found.');
            $now = Clock::now();
            $this->expireSessionCheckouts($pdo, $sessionId);
            $pending = $pdo->prepare("SELECT pa.id,pa.reference,pa.payment_type,pa.maya_redirect_url,pa.amount_centavos,pa.created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND) AS stale,e.duration_minutes FROM payment_attempts pa LEFT JOIN parking_extensions e ON e.payment_attempt_id=pa.id WHERE pa.parking_session_id=? AND pa.local_status IN ('created','checkout_created','pending_verification') ORDER BY pa.id DESC FOR UPDATE");
            $pending->execute([$sessionId]);
            foreach ($pending->fetchAll() as $old) {
                if (!$old['maya_redirect_url']) {
                    // Checkout creation is in flight; never request a second Maya checkout for the same reference.
                    if (!$old['stale']) throw new \InvalidArgumentException('A Maya checkout is already being prepared for this session. Try again in a moment.');
                    $this->failSessionAttempt($pdo, (int) $old['id'], 'Checkout creation did not complete.');
                    continue;
                }
                if ($old['payment_type'] !== $type) throw new \InvalidArgumentException($type === 'OVERTIME_SETTLEMENT'
                    ? 'A parking extension checkout is still pending and will also cover this overtime once verified. Complete it or wait for it to expire.'
                    : 'An overtime checkout is still pending. Complete it before extending this session.');
                if ($type === 'PARKING_EXTENSION' && (int) $old['duration_minutes'] !== $hours * 60) throw new \InvalidArgumentException('A ' . intdiv((int) $old['duration_minutes'], 60) . '-hour extension checkout is already pending. Complete it or wait for it to expire before choosing another duration.');
                return ['id' => (int) $old['id'], 'reference' => $old['reference'], 'maya_redirect_url' => $old['maya_redirect_url'], 'amount_centavos' => (int) $old['amount_centavos']];
            }
            $overtime = (new \ParkNGo\Services\Parking\OvertimeService())->calculate($session, $now->format('Y-m-d H:i:s'), $pdo);
            if ($type === 'OVERTIME_SETTLEMENT') {
                if (!$overtime['payment_required'] || $overtime['estimated_centavos'] === null) throw new \InvalidArgumentException('This session has no payable overtime amount yet.');
                $start = $overtime['chargeable_from']; $end = $overtime['server_now']; $seconds = (int) $overtime['elapsed_seconds']; $amount = (int) $overtime['estimated_centavos'];
            } else {
                if ($overtime['payment_required']) throw new \InvalidArgumentException('Settle verified overtime before extending this session.');
                $minutes = $hours * 60; $rate = (int) ($session['succeeding_hour_centavos'] ?? 0); if ($rate <= 0) throw new \InvalidArgumentException('This facility has no extension rate configured.');
                // Within paid time the extension is contiguous; during settlement grace it starts now and supersedes the remaining grace.
                $paidUntil = new \DateTimeImmutable((string) $session['paid_until'], new \DateTimeZone('UTC'));
                $start = ($paidUntil > $now ? $paidUntil : $now)->format('Y-m-d H:i:s');
                $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
                if ($conflict = $this->extensionConflict($pdo, (int) $session['slot_id'], (int) $session['reservation_id'], $start, $end)) throw new \InvalidArgumentException($conflict);
                $seconds = $minutes * 60; $amount = $rate * $hours;
            }
            $reference = 'PNGO-' . strtoupper(bin2hex(random_bytes(12)));
            $pdo->prepare("INSERT INTO payment_attempts (reference,user_id,parking_session_id,payment_type,amount_centavos,currency,maya_request_reference,local_status,checkout_expires_at) VALUES (?,?,?,?,?,'PHP',?,'created',DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))")->execute([$reference,$userId,$sessionId,$type,$amount,$reference]);
            $attemptId=(int)$pdo->lastInsertId();
            if ($type === 'OVERTIME_SETTLEMENT') $pdo->prepare("INSERT INTO parking_overtime_settlements (parking_session_id,payment_attempt_id,interval_start,interval_end,billed_seconds,rate_centavos,amount_centavos) VALUES (?,?,?,?,?,?,?)")->execute([$sessionId,$attemptId,$start,$end,$seconds,(int)$session['overtime_hour_centavos'],$amount]);
            else $pdo->prepare("INSERT INTO parking_extensions (parking_session_id,payment_attempt_id,duration_minutes,amount_centavos,rate_centavos,paid_until_before,requested_paid_until) VALUES (?,?,?,?,?,?,?)")->execute([$sessionId,$attemptId,$hours*60,$amount,(int)$session['succeeding_hour_centavos'],$session['paid_until'],$end]);
            Audit::record('payment.session_checkout_started', $userId, $attemptId, 'payment_attempt');
            return ['id'=>$attemptId,'reference'=>$reference,'amount_centavos'=>$amount,'maya_redirect_url'=>null];
        });
    }

    private function createCheckout(array $attempt, string $label): string
    {
        if (!empty($attempt['maya_redirect_url'])) return (string)$attempt['maya_redirect_url'];
        try {
            $client = new MayaClient();
            $returnBase = rtrim(Environment::get('APP_URL', 'http://localhost/park-n-go/public'), '/');
            $returnUrl = $returnBase . '/' . url('payment-return', ['reference' => $attempt['reference']]);
            $value = number_format($attempt['amount_centavos'] / 100, 2, '.', '');
            $checkout = $client->createCheckout([
                'totalAmount' => ['value' => $value, 'currency' => 'PHP'],
                'buyer' => ['firstName' => 'Park n Go', 'lastName' => 'Driver'],
                'items' => [['name' => $label, 'quantity' => 1, 'amount' => ['value' => $value], 'totalAmount' => ['value' => $value]]],
                'redirectUrl' => ['success' => $returnUrl, 'failure' => $returnUrl, 'cancel' => $returnUrl],
                'requestReferenceNumber' => $attempt['reference'],
                'metadata' => ['parkngo_payment_reference' => $attempt['reference']],
            ]);
            Database::connection()->prepare("UPDATE payment_attempts SET maya_checkout_id=?,maya_redirect_url=?,local_status='checkout_created' WHERE id=? AND local_status='created'")->execute([$checkout['checkout_id'],$checkout['redirect_url'],$attempt['id']]);
            return $checkout['redirect_url'];
        } catch (\Throwable $error) {
            error_log('Maya session checkout creation failed for payment attempt ' . (int) $attempt['id'] . ': ' . get_class($error));
            Database::transaction(fn (\PDO $pdo) => $this->failSessionAttempt($pdo, (int) $attempt['id'], 'Checkout creation failed.'));
            throw $error;
        }
    }

    /** Release a session checkout that never reached Maya; its ledger row stops protecting the session. */
    private function failSessionAttempt(\PDO $pdo, int $attemptId, string $reason): void
    {
        $update = $pdo->prepare("UPDATE payment_attempts SET local_status='failed',failure_reason=? WHERE id=? AND local_status='created'");
        $update->execute([$reason, $attemptId]);
        if ($update->rowCount() !== 1) return;
        $pdo->prepare("UPDATE parking_extensions SET status='failed' WHERE payment_attempt_id=? AND status='pending'")->execute([$attemptId]);
        $pdo->prepare("UPDATE parking_overtime_settlements SET status='failed' WHERE payment_attempt_id=? AND status='pending'")->execute([$attemptId]);
    }

    /** Expire lapsed overtime and extension checkouts together with their ledger rows. A later success enters reconciliation. */
    public function expireSessionCheckouts(?\PDO $pdo = null, ?int $sessionId = null): int
    {
        $run = function (\PDO $pdo) use ($sessionId): int {
            $find = $pdo->prepare("SELECT id FROM payment_attempts WHERE payment_type IN ('PARKING_EXTENSION','OVERTIME_SETTLEMENT') AND local_status IN ('created','checkout_created','pending_verification') AND checkout_expires_at IS NOT NULL AND checkout_expires_at<=UTC_TIMESTAMP()" . ($sessionId ? ' AND parking_session_id=?' : '') . ' ORDER BY id LIMIT 500 FOR UPDATE');
            $find->execute($sessionId ? [$sessionId] : []); $ids = $find->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $pdo->prepare("UPDATE payment_attempts SET local_status='expired',failure_reason='Checkout expired.' WHERE id=?")->execute([$id]);
                $pdo->prepare("UPDATE parking_extensions SET status='expired' WHERE payment_attempt_id=? AND status='pending'")->execute([$id]);
                $pdo->prepare("UPDATE parking_overtime_settlements SET status='expired' WHERE payment_attempt_id=? AND status='pending'")->execute([$id]);
            }
            return count($ids);
        };
        return $pdo ? $run($pdo) : Database::transaction($run);
    }

    /** Extensions may not overlap the slot's confirmed bookings, live holds, or protected pending checkouts (0-minute buffer). */
    private function extensionConflict(\PDO $pdo, int $slotId, int $reservationId, string $start, string $end): ?string
    {
        $find = $pdo->prepare("SELECT 1 FROM reservations WHERE slot_id=? AND id<>? AND status='confirmed' AND starts_at<? AND ends_at>? LIMIT 1");
        $find->execute([$slotId, $reservationId, $end, $start]);
        if ($find->fetchColumn()) return 'This space is reserved by another driver during the requested extension. Choose a shorter extension.';
        $find = $pdo->prepare('SELECT 1 FROM reservation_holds WHERE slot_id=? AND (reservation_id IS NULL OR reservation_id<>?) AND released_at IS NULL AND expires_at>UTC_TIMESTAMP() AND starts_at<? AND ends_at>? LIMIT 1');
        $find->execute([$slotId, $reservationId, $end, $start]);
        if ($find->fetchColumn()) return 'Another checkout is holding this space during the requested extension. Choose a shorter extension.';
        $find = $pdo->prepare("SELECT 1 FROM reservations r JOIN payment_attempts pa ON pa.reservation_id=r.id WHERE r.slot_id=? AND r.id<>? AND r.status='pending' AND pa.payment_type='INITIAL_RESERVATION' AND pa.local_status IN ('created','checkout_created','pending_verification') AND (pa.checkout_expires_at IS NULL OR pa.checkout_expires_at>UTC_TIMESTAMP()) AND r.starts_at<? AND r.ends_at>? LIMIT 1");
        $find->execute([$slotId, $reservationId, $end, $start]);
        if ($find->fetchColumn()) return 'Another driver is completing payment for this space during the requested extension. Choose a shorter extension.';
        return null;
    }

    private function reconcile(\PDO $pdo, array $attempt, string $status, string $reason, string $event): void
    {
        $pdo->prepare("UPDATE payment_attempts SET provider_status=?,local_status='reconciliation',reconciliation_reason=? WHERE id=? AND local_status<>'verified'")->execute([$status, $reason, $attempt['id']]);
        $ledger = $attempt['payment_type'] === 'PARKING_EXTENSION' ? 'parking_extensions' : ($attempt['payment_type'] === 'OVERTIME_SETTLEMENT' ? 'parking_overtime_settlements' : null);
        if ($ledger) $pdo->prepare("UPDATE {$ledger} SET status='reconciliation' WHERE payment_attempt_id=? AND status<>'verified'")->execute([$attempt['id']]);
        Audit::record($event, (int) $attempt['user_id'], (int) $attempt['id'], 'payment_attempt');
    }

    public function attempt(int $userId, string $reference): array
    {
        $find = Database::connection()->prepare('SELECT p.*, r.reference AS reservation_reference FROM payment_attempts p LEFT JOIN reservations r ON r.id=p.reservation_id WHERE p.user_id=? AND p.reference=?');
        $find->execute([$userId, $reference]); $row = $find->fetch();
        if (!$row) throw new \OutOfBoundsException('Payment attempt not found.');
        return $row;
    }
}
