<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Development mode
    |--------------------------------------------------------------------------
    |
    | If set to true caching is done in memory with the ArrayCache. Proxy objects are recreated on every request.
    | If it's false, use the systems temporary directory
    |
    */
    'development_mode' => false,

    /*
    |--------------------------------------------------------------------------
    | Files path
    |--------------------------------------------------------------------------
    |
    | The absolute path to xml-mappings, entities and proxies files
    |
    */
    'namespace' => 'caca',
    'entities_path' => base_path('database/doctrine/entities'),
    'xml_mappings_path' => base_path('database/doctrine/xml-mappings'),
    // 'proxies_path' => base_path('database/doctrine/proxies'),

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
        'days' => 1,
    ],

];
