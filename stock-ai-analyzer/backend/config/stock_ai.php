<?php

declare(strict_types=1);

return [

    /*
    | Python analysis engine (docs/05-api-spec.md §4). Requests to /v1/* are HMAC-signed.
    */
    'engine' => [
        'url' => env('ENGINE_URL', 'http://localhost:8100'),
        'hmac_secret' => env('ENGINE_HMAC_SECRET'),
        'connect_timeout' => (int) env('ENGINE_CONNECT_TIMEOUT', 3),
        'health_timeout' => (int) env('ENGINE_HEALTH_TIMEOUT', 5),
        'request_timeout' => (int) env('ENGINE_REQUEST_TIMEOUT', 30),
        'analyze_timeout' => (int) env('ENGINE_ANALYZE_TIMEOUT', 900),
        // A stage may run long, but total silence for this many seconds means the run is stalled.
        'stream_read_timeout' => (int) env('ENGINE_STREAM_READ_TIMEOUT', 120),
    ],

    /*
    | Local owner account created by the UserSeeder. No account is created without a password.
    */
    'owner' => [
        'name' => env('OWNER_NAME', 'Owner'),
        'email' => env('OWNER_EMAIL'),
        'password' => env('OWNER_PASSWORD'),
    ],

    /*
    | Chart screenshot uploads (docs/05-api-spec.md §3.1). Stored on the private `charts` disk; paths are
    | relative to that disk, so the engine (storage root /storage) reads them as charts/{path}.
    | php.ini upload_max_filesize must stay above max_bytes so oversized files reach the size check.
    */
    'uploads' => [
        'disk' => 'charts',
        'max_bytes' => 10 * 1024 * 1024,
        'min_width' => 600,
        'min_height' => 400,
        'max_width' => 8000,
        'max_height' => 8000,
        'mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
    ],

    /*
    | CSV price import (docs/csv-format.md) and the merolagani provider (docs/market-data.md).
    */
    'market_data' => [
        'csv_max_rows' => 100_000,
        'max_skipped_listed' => 200,

        'merolagani' => [
            'url' => env('MEROLAGANI_URL', 'https://eng.merolagani.com'),
            // Bonus- and rights-adjusted prices. NEPSE bonus issues would otherwise show up as
            // overnight gaps that indicators and pattern detectors read as real moves.
            'adjusted' => (bool) env('MEROLAGANI_ADJUSTED', true),
            // How far back a routine run asks for. Covers a late or restated bar and a few missed
            // days; a stock with no bars yet gets the whole history regardless.
            'lookback_days' => (int) env('MEROLAGANI_LOOKBACK_DAYS', 10),
            // Earliest date a backfill asks for. NEPSE data on merolagani starts in the 2000s.
            'history_start' => env('MEROLAGANI_HISTORY_START', '2000-01-01'),
            // Seconds between symbols in a scheduled run: one courtesy call a day, spread out.
            'spacing_seconds' => (int) env('MEROLAGANI_SPACING_SECONDS', 2),
            'timeout' => (int) env('MEROLAGANI_TIMEOUT', 30),
            'connect_timeout' => (int) env('MEROLAGANI_CONNECT_TIMEOUT', 10),
            'retries' => (int) env('MEROLAGANI_RETRIES', 3),
            'retry_delay_ms' => (int) env('MEROLAGANI_RETRY_DELAY_MS', 1000),
            'user_agent' => env('MEROLAGANI_USER_AGENT', 'stock-ai-analyzer/1.0 (local, personal use)'),
        ],

        // Sharesansar fills what merolagani cannot: instruments it does not chart, and turnover.
        // See docs/market-data.md.
        'sharesansar' => [
            'url' => env('SHARESANSAR_URL', 'https://www.sharesansar.com'),
            'lookback_days' => (int) env('SHARESANSAR_LOOKBACK_DAYS', 10),
            'spacing_seconds' => (int) env('SHARESANSAR_SPACING_SECONDS', 2),
            'timeout' => (int) env('SHARESANSAR_TIMEOUT', 30),
            'connect_timeout' => (int) env('SHARESANSAR_CONNECT_TIMEOUT', 10),
            'retries' => (int) env('SHARESANSAR_RETRIES', 3),
            'retry_delay_ms' => (int) env('SHARESANSAR_RETRY_DELAY_MS', 1000),
            'user_agent' => env('SHARESANSAR_USER_AGENT', 'stock-ai-analyzer/1.0 (local, personal use)'),
        ],
    ],

    'default_user_settings' => [
        'account_size' => null,
        'currency' => 'NPR',
        'risk_per_trade_pct' => 1.0,
        'max_position_pct' => 25.0,
        'default_style' => 'swing',
        'min_rr_override' => null,
        'model_profile' => env('MODEL_PROFILE', 'gpu_4gb'),
    ],

];
