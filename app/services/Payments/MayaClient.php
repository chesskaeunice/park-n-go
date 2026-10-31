<?php
declare(strict_types=1);
namespace ParkNGo\Services\Payments;

use ParkNGo\Services\{Environment};
use ParkNGo\Services\Api\Json;

final class MayaClient
{
    private array $config;

    public function __construct()
    {
        $this->config = require BASE_PATH . '/config/maya.php';
        if (!$this->config['enabled'] || $this->config['mode'] !== 'sandbox') {
            throw new \RuntimeException('Maya Checkout is disabled or not configured for sandbox mode.');
        }
        if ($this->config['public_key'] === '' || $this->config['secret_key'] === '') {
            throw new \RuntimeException('Maya sandbox credentials are not configured.');
        }
    }

    public function createCheckout(array $payload): array
    {
        $response = $this->request('POST', '/checkout/v1/checkouts', $payload, $this->config['public_key']);
        if (!isset($response->checkoutId, $response->redirectUrl)) throw new \RuntimeException('Maya returned an incomplete checkout response.');
        $redirect = is_object($response->redirectUrl) ? ($response->redirectUrl->checkout ?? null) : $response->redirectUrl;
        if (!is_string($redirect) || !str_starts_with($redirect, 'https://')) throw new \RuntimeException('Maya returned an unsafe checkout URL.');
        return ['checkout_id' => (string) $response->checkoutId, 'redirect_url' => $redirect, 'raw' => $response];
    }

    public function retrievePayment(string $paymentId): \stdClass
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $paymentId)) throw new \InvalidArgumentException('Invalid Maya payment identifier.');
        return $this->request('GET', '/payments/v1/payments/' . rawurlencode($paymentId), null, $this->config['secret_key']);
    }

    /** Read-only refund evidence. Park n Go never submits provider refunds itself. */
    public function retrieveRefund(string $paymentId, string $refundId): \stdClass
    {
        foreach ([$paymentId, $refundId] as $id) if (!preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $id)) throw new \InvalidArgumentException('Invalid Maya refund identifier.');
        return $this->request('GET', '/payments/v1/payments/' . rawurlencode($paymentId) . '/refunds/' . rawurlencode($refundId), null, $this->config['secret_key']);
    }

    private function request(string $method, string $path, ?array $payload, string $key): \stdClass
    {
        $handle = curl_init($this->config['api_origin'] . $path);
        if ($handle === false) throw new \RuntimeException('Unable to initialize Maya transport.');
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . base64_encode($key . ':')];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $body = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if ($body === false) throw new \RuntimeException('Maya is temporarily unavailable.');
        if ($status < 200 || $status >= 300) {
            error_log('Maya request failed with HTTP ' . $status);
            if (Environment::get('APP_ENV', 'local') === 'local') {
                error_log('Maya sandbox response: ' . substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 1000));
            }
            throw new MayaApiException('Maya did not accept the payment request.', $status, $body);
        }
        try { return Json::decode($body); } catch (\Throwable) { throw new \RuntimeException('Maya returned an invalid response.'); }
    }
}
