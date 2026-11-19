<?php

/*
 * Untasked hosted Postgres: DATABASE_URL (runtime, DML) and MIGRATION_DATABASE_URL (migrator, DDL)
 * carry a percent-encoded password and ?sslmode=require. Laravel's URL parser decodes both, so pass
 * the URL through instead of splitting it with parse_url. The platform grants the runtime role the
 * `app` schema only — tables created in `public` are unreadable at runtime.
 */
return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL', env('DATABASE_URL')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SEARCH_PATH', 'app'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
];
