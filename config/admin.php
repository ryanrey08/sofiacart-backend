<?php

return [
    'auth' => [
        'token_ttl_minutes' => (int) env('ADMIN_TOKEN_TTL_MINUTES', 120),
    ],
];
