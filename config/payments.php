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
    | only, so merchants cannot mark their payments completed by hand. HitPay
    | (QR Ph, GCash, Credit Card) is integrated in the customer storefront
    | backend (sofiacart-website-backend): its payments are recorded with the
    | "hitpay" gateway and settled by a signature-verified HitPay webhook, so
    | they are read-only here. Manual payments keep the "manual" gateway and
    | are confirmed by an authorized merchant/admin. Gateway credentials must
    | be read from environment variables, never stored in the database or
    | returned by the API.
    |
    */

    'default_gateway' => 'manual',

    'integrated_gateways' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENT_INTEGRATED_GATEWAYS', 'hitpay')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Payment proof attachments
    |--------------------------------------------------------------------------
    */

    'attachments' => [
        'disk' => 'local',
        'directory' => 'payment-proofs',
        'max_files' => 5,
        // Same limit as every other image upload (config/uploads.php).
        'max_kilobytes' => (int) env('UPLOAD_IMAGE_MAX_KB', 10240),
    ],

];
