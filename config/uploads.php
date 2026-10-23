<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image uploads
    |--------------------------------------------------------------------------
    |
    | Maximum size, in kilobytes, of every upload that accepts images: product and category images,
    | store logo and banner, business permit and government ID, return evidence and payment proofs.
    | PHP's upload_max_filesize / post_max_size (docker/php/uploads.ini) and nginx's
    | client_max_body_size must allow it.
    |
    */

    'image_max_kilobytes' => (int) env('UPLOAD_IMAGE_MAX_KB', 10240),

];
