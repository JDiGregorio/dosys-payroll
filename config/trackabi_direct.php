<?php

return [
    'enabled' => env('TRACKABI_DIRECT_API_ENABLED', false),
    'base_url' => rtrim(env('TRACKABI_DIRECT_API_BASE_URL', 'https://api.trackabi.com'), '/'),
    'api_key' => env('TRACKABI_DIRECT_API_KEY'),
    'timeout' => (int) env('TRACKABI_DIRECT_API_TIMEOUT', 30),
    'verify_ssl' => env('TRACKABI_DIRECT_API_VERIFY_SSL', true),
    'store_raw_responses' => env('TRACKABI_DIRECT_STORE_RAW_RESPONSES', false),
    'fail_open' => env('TRACKABI_DIRECT_FAIL_OPEN', true),

    // Reserved for an activity provider with a confirmed response contract.
    'activity_enabled' => env('TRACKABI_DIRECT_ACTIVITY_ENABLED', false),
    'activity_import_from_date' => env('TRACKABI_DIRECT_ACTIVITY_IMPORT_FROM_DATE'),
    'activity_lookback_days' => (int) env('TRACKABI_DIRECT_ACTIVITY_LOOKBACK_DAYS', 3),
    'activity_gap_review_minutes' => (int) env('TRACKABI_ACTIVITY_GAP_REVIEW_MINUTES', 30),
];
