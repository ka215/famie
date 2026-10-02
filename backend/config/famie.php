<?php

return [
    'ip_restriction_enabled' => env('IP_RESTRICTION_ENABLED', true),
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_IPS', '')),
    ))),
    'max_group_members' => (int) env('FAMIE_MAX_GROUP_MEMBERS', 10),
    'max_group_categories' => (int) env('FAMIE_MAX_GROUP_CATEGORIES', 10),
    'register_window_seconds' => (int) env('FAMIE_REGISTER_WINDOW_SECONDS', 600),
    'register_max_attempts' => (int) env('FAMIE_REGISTER_MAX_ATTEMPTS', 5),
    'register_cooldown_seconds' => (int) env('FAMIE_REGISTER_COOLDOWN_SECONDS', 600),
];
