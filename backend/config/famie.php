<?php

return [
    'ip_restriction_enabled' => env('IP_RESTRICTION_ENABLED', true),
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_IPS', '')),
    ))),
];
