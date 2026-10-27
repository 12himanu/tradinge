<?php

declare(strict_types=1);

/*
| The browser normally reaches the API through the Next.js rewrite (same origin), so CORS only matters
| when something calls :8000 directly. Only the frontend origin may do that, with credentials
| (docs/05-api-spec.md §1).
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [(string) env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,

];
