<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ],
        'untasked' => [
            'driver' => 'untasked',
            'url' => env('UNTASKED_STORAGE_URL'),
            'token' => env('UNTASKED_STORAGE_TOKEN'),
            'throw' => true,
        ],
    ],
];
