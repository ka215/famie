<?php

return [
    'version' => json_decode(file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR)['version'],
    'environment' => env('FAMIE_OPERATIONS_ENVIRONMENT', env('APP_ENV', 'production')),
    'enabled' => (bool) env('FAMIE_OPERATIONS_ENABLED', false),
    'recipient' => env('FAMIE_OPERATIONS_RECIPIENT'),
    'mailer' => env('FAMIE_OPERATIONS_MAILER', 'smtp'),
    'suppressed_until' => env('FAMIE_OPERATIONS_SUPPRESSED_UNTIL'),
    'quota_bytes' => (int) env('FAMIE_OPERATIONS_QUOTA_BYTES', 500000000000),
    'quota_roots' => array_values(array_filter(explode(',', (string) env('FAMIE_OPERATIONS_QUOTA_ROOTS', '')))),
    'backup_time' => env('FAMIE_OPERATIONS_BACKUP_TIME', '03:00'),
];
