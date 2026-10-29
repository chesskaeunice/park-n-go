<?php
declare(strict_types=1);

use ParkNGo\Services\Environment;

// Private configuration only. No routes or provider calls are enabled in Phase 2.
return [
    'provider' => 'paymongo',
    'implementation_status' => 'pending_phase_6',
    'enabled' => false,
    'mode' => Environment::get('PAYMENT_MODE', 'sandbox'),
    'currency' => 'PHP',
    'api_origin' => 'https://api.paymongo.com',
    'checkout_api_version' => 'v2',
    'secret_key' => Environment::get('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => Environment::get('PAYMONGO_WEBHOOK_SECRET'),
];
