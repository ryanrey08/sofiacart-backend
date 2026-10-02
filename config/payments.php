<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store currency
    |--------------------------------------------------------------------------
    |
    | Orders carry no currency of their own, so every payment and refund is
    | recorded in this ISO 4217 currency.
    |
    */

    'currency' => env('PAYMENT_CURRENCY', 'PHP'),

    /*
    |--------------------------------------------------------------------------
    | Pending payment expiry
    |--------------------------------------------------------------------------
    |
    | Pending payments (except cash on delivery) expire after this many
    | minutes. `php artisan payments:expire` (scheduled every five minutes)
    | moves them to "expired".
    |
    */

    'pending_expiry_minutes' => (int) env('PAYMENT_PENDING_EXPIRY_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Integrated gateways
    |--------------------------------------------------------------------------
    |
    | Gateways listed here confirm payments through verified gateway callbacks
    | only, so merchants cannot mark their payments completed by hand. No
    | gateway is integrated yet; every payment is recorded with the "manual"
    | gateway and confirmed by an authorized merchant/admin. Gateway
    | credentials must be read from environment variables, never stored in
    | the database or returned by the API.
    |
    */

    'default_gateway' => 'manual',

    'integrated_gateways' => [],

    /*
    |--------------------------------------------------------------------------
    | Payment proof attachments
    |--------------------------------------------------------------------------
    */

    'attachments' => [
        'disk' => 'local',
        'directory' => 'payment-proofs',
        'max_files' => 5,
        'max_kilobytes' => 5120,
    ],

];
