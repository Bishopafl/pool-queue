<?php

/*
 * Cross-origin requests. Only the camera API is called from another site: the Pool
 * Vision phone page (pv.adamlopez.co) pairs and posts pots with a Bearer
 * token, so no cookies cross over (supports_credentials stays false).
 */
return [

    'paths' => ['api/vision/*'],

    'allowed_methods' => ['GET', 'POST'],

    // comma-separated, e.g. "https://pv.adamlopez.co,http://localhost:8010"
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VISION_ALLOWED_ORIGINS', 'https://pv.adamlopez.co')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
