<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Files path
    |--------------------------------------------------------------------------
    |
    | The absolute path to your xml-mappings files
    |
    */
    'xml_mappings_path' => base_path('database/doctrine/xml-mappings'),

    /*
    |--------------------------------------------------------------------------
    | Schema filter
    |--------------------------------------------------------------------------
    |
    | Optional regex of table names to ignore, both when reading the current database schema and when
    | comparing it against the xml-mappings. Useful to prevent doctrine:update from proposing to drop
    | tables that are not managed by this package. Example: '/^(spatial_ref_sys|other_table)$/'
    |
    */
    'schema_filter' => null,

    /*
    |--------------------------------------------------------------------------
    | Database connection data
    |--------------------------------------------------------------------------
    |
    | MySQL connection data
    |
    */
    'db_host' => env('DB_HOST', '127.0.0.1'),
    'db_port' => env('DB_PORT', '3306'),
    'db_username' => env('DB_USERNAME', ''),
    'db_password' => env('DB_PASSWORD', ''),
    'db_database' => env('DB_DATABASE', ''),
    'db_charset' => env('DB_CHARSET', 'utf8mb4'),

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Save logs when a database is updated
    |
    */
    'save_logs' => true,
    'logging' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel-doctrine.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'days' => 14,
        'replace_placeholders' => true,
    ],

];
