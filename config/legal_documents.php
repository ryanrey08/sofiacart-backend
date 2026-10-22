<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Legal documents are stored on a private disk (see the "legal" disk in filesystems.php) and
    | are only ever streamed through the API. sofiacart-website-backend reads the same files to
    | serve published versions to customers, so in production both APIs must point at the same
    | storage (a shared volume or the same S3 bucket).
    |
    */

    'disk' => env('LEGAL_DOCUMENTS_DISK', 'legal'),

    // Maximum upload size in kilobytes (PHP's upload_max_filesize must allow it; see docker/php/uploads.ini).
    'max_kilobytes' => (int) env('LEGAL_DOCUMENTS_MAX_KB', 10240),

    /*
    |--------------------------------------------------------------------------
    | Registration acceptance
    |--------------------------------------------------------------------------
    |
    | When true, merchant registration requires accepting the published merchant Terms and
    | acknowledging the published merchant Privacy Policy. If either is not published,
    | registration is refused until an admin publishes one.
    |
    */

    'require_registration_acceptance' => (bool) env('LEGAL_DOCUMENTS_REQUIRE_ACCEPTANCE', true),

];
