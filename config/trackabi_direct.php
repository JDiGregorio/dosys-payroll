<?php

return [
    'enabled' => env('TRACKABI_DIRECT_API_ENABLED', false),
    'base_url' => rtrim(env('TRACKABI_DIRECT_API_BASE_URL', 'https://api.trackabi.com'), '/'),
    'api_key' => env('TRACKABI_DIRECT_API_KEY'),
    'timeout' => (int) env('TRACKABI_DIRECT_API_TIMEOUT', 30),
    'verify_ssl' => env('TRACKABI_DIRECT_API_VERIFY_SSL', true),
    'store_raw_responses' => env('TRACKABI_DIRECT_STORE_RAW_RESPONSES', false),
    'cutover_date' => env('TRACKABI_DIRECT_CUTOVER_DATE'),
    'import_page_size' => (int) env('TRACKABI_DIRECT_IMPORT_PAGE_SIZE', 100),
    'import_max_pages' => (int) env('TRACKABI_DIRECT_IMPORT_MAX_PAGES', 100),
    'max_entry_hours' => (int) env('TRACKABI_DIRECT_MAX_ENTRY_HOURS', 24),
    'special_projects_from_date' => '2026-10-21',
    'operations_final_review_from_date' => '2026-10-11',
    'special_projects' => [
        81118 => 'paid_break_10',
        81119 => 'paid_break_15',
        81120 => 'paid_lunch',
    ],
    'insights_ignored_names' => ['Delcid, David', 'Rodas, Rocio', 'GARCIA, JONATHAN'],
    'direct_ignored_member_ids' => [63936], // David Delcid: no payroll employee.
    'insights_paid_lunch_minutes' => (int) env('TRACKABI_INSIGHTS_PAID_LUNCH_MINUTES', 60),
    'insights_paid_break_minutes' => (int) env('TRACKABI_INSIGHTS_PAID_BREAK_MINUTES', 15),
    'insights_paid_time_policies' => [
        'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20, 'break_minutes_per_occurrence' => 10, 'break_occurrences' => 2],
        'AUTO FINANCE CENTER' => ['lunch_minutes' => 60, 'break_minutes' => 30, 'break_minutes_per_occurrence' => 15, 'break_occurrences' => 2],
        'PALMETTO' => ['lunch_minutes' => 60, 'break_minutes' => 10, 'break_minutes_per_occurrence' => 10, 'break_occurrences' => 1],
    ],
    'insights_special_paid_time_member_ids' => [
        63566 => ['lunch_minutes' => 60, 'break_minutes' => 30, 'break_minutes_per_occurrence' => 15, 'break_occurrences' => 2], // Victor Vasquez
        63567 => ['lunch_minutes' => 60, 'break_minutes' => 30, 'break_minutes_per_occurrence' => 15, 'break_occurrences' => 2], // Marco Lara
        63565 => ['lunch_minutes' => 60, 'break_minutes' => 30, 'break_minutes_per_occurrence' => 15, 'break_occurrences' => 2], // Kelly Urquia
    ],

    // Reserved for an activity provider with a confirmed response contract.
    'activity_enabled' => env('TRACKABI_DIRECT_ACTIVITY_ENABLED', false),
    'activity_import_from_date' => env('TRACKABI_DIRECT_ACTIVITY_IMPORT_FROM_DATE'),
    'activity_lookback_days' => (int) env('TRACKABI_DIRECT_ACTIVITY_LOOKBACK_DAYS', 3),
    'activity_gap_review_minutes' => (int) env('TRACKABI_ACTIVITY_GAP_REVIEW_MINUTES', 30),
];
