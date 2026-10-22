<?php

return [
    // The admin/merchant API is called only by the Super Admin + merchant frontend (and its public
    // legal-document endpoints are fetched from that same origin). Lock cross-origin access to it,
    // instead of the framework default that allows any origin.
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([env('FRONTEND_URL', 'http://localhost:3000')]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Retry-After'],
    'max_age' => 0,
    'supports_credentials' => false,
];
