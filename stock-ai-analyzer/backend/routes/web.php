<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The UI is the Next.js app; the backend only serves the API, Horizon and API docs.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'docs' => url('/docs/api'),
    'horizon' => url('/horizon'),
]));
