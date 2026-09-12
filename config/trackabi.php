<?php

return [
    'enabled' => env('TRACKABI_ENABLED', false),
    'provider' => env('TRACKABI_PROVIDER', 'mindcloud'),

    'api_base_url' => rtrim(env('TRACKABI_API_BASE_URL', 'https://connect.mindcloud.co/v1/universal/trackabi/latest'), '/'),
    'api_token' => env('MINDCLOUD_API_KEY', env('TRACKABI_API_TOKEN')),
    'connection_id' => env('TRACKABI_CONNECTION_ID'),
    'list_time_entries_path' => env('TRACKABI_LIST_TIME_ENTRIES_PATH', '/actions/list-time-entries'),
    'auth_scheme' => env('TRACKABI_AUTH_SCHEME', 'bearer'),
    'auth_header' => env('TRACKABI_AUTH_HEADER', 'Authorization'),
    'connection_id_location' => env('TRACKABI_CONNECTION_ID_LOCATION', 'query'),
    'connection_id_header' => env('TRACKABI_CONNECTION_ID_HEADER', 'X-Connection-Id'),
    'timeout' => (int) env('TRACKABI_API_TIMEOUT', 30),
    'retry_times' => (int) env('TRACKABI_API_RETRY_TIMES', 2),
    'retry_sleep_ms' => (int) env('TRACKABI_API_RETRY_SLEEP_MS', 500),

    'default_project_name' => env('TRACKABI_DEFAULT_PROJECT_NAME', 'Palmetto'),
    'palmetto_project_id' => (int) env('TRACKABI_PALMETTO_PROJECT_ID', 75415),
    'filter_by_project_id' => env('TRACKABI_FILTER_BY_PROJECT_ID', false),
    'estimate_real_time' => env('TRACKABI_ESTIMATE_REAL_TIME', false),
    'estimated_loss_max_minutes' => (int) env('TRACKABI_ESTIMATED_LOSS_MAX_MINUTES', 15),
    'credited_break_minutes' => (int) env('TRACKABI_CREDITED_BREAK_MINUTES', 75),
    'historical_loss_period_ids' => array_filter(array_map(
        'intval',
        explode(',', env('TRACKABI_HISTORICAL_LOSS_PERIOD_IDS', '5,6,7')),
    )),
    'import_from_date' => env('TRACKABI_IMPORT_FROM_DATE'),
    'import_to_date' => env('TRACKABI_IMPORT_TO_DATE'),
    'filter_dates_locally' => env('TRACKABI_FILTER_DATES_LOCALLY', true),
    'import_limit' => (int) env('TRACKABI_IMPORT_LIMIT', 100),
    'import_max_pages' => (int) env('TRACKABI_IMPORT_MAX_PAGES', 100),
    'fields' => env(
        'TRACKABI_FIELDS',
        'id,dateLogged,loggedTime,member.email,member.firstName,member.lastName,project.id,project.name,startTime,endTime,timeType',
    ),

    'conflict_strategy' => env('TRACKABI_CONFLICT_STRATEGY', 'manual_review_on_overlap'),

    'productivity_adjustment_enabled' => env('TRACKABI_PRODUCTIVITY_ADJUSTMENT_ENABLED', false),
    'slack_adjustment_enabled' => env('TRACKABI_SLACK_ADJUSTMENT_ENABLED', false),
    'min_activity_score_for_full_credit' => (float) env('TRACKABI_MIN_ACTIVITY_SCORE_FOR_FULL_CREDIT', 70),
    'min_activity_score_for_review' => (float) env('TRACKABI_MIN_ACTIVITY_SCORE_FOR_REVIEW', 40),

    'palmetto_emails' => [
        'irisrivera7704@gmail.com',
        'justinbodden14@gmail.com',
        'ramonanikera@gmail.com',
        'yadyebanks_2185@yahoo.com',
        'jesefont92@gmail.com',
        'marco_alberty13@hotmail.com',
        'genesismyvett27@gmail.com',
        'refsmaradiaga@gmail.com',
        'lanzarodriguez23@gmail.com',
        'jasminrociosanchez@gmail.com',
        'franz17@hotmail.com',
        'kelly.urquia.7@gmail.com',
        'vavn300@gmail.com',
        'forleyforbes@gmail.com',
    ],
];
