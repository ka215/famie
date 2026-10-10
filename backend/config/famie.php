<?php

$likeRateLimit = env('FAMIE_LIKE_RATE_LIMIT_PER_SECOND', 2);

return [
    'like_rate_limit_per_second' => (is_int($likeRateLimit) || is_string($likeRateLimit))
        ? filter_var($likeRateLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false,
    'frontend_url' => env('FAMIE_FRONTEND_URL', 'http://localhost:3000'),
    'mail_restrict_recipients' => env('FAMIE_MAIL_RESTRICT_RECIPIENTS', true),
    'mail_allowed_recipients' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('FAMIE_MAIL_ALLOWED_RECIPIENTS', '')))))),
    'ip_restriction_enabled' => env('IP_RESTRICTION_ENABLED', true),
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_IPS', '')),
    ))),
    'max_group_members' => (int) env('FAMIE_MAX_GROUP_MEMBERS', 10),
    'max_group_categories' => (int) env('FAMIE_MAX_GROUP_CATEGORIES', 10),
    'image_disk' => env('FAMIE_IMAGE_DISK', 'local'),
    'image_max_files' => 1,
    'image_max_input_kb' => 30720,
    'image_max_pixels' => 48000000,
    'image_long_edge' => 1600,
    'image_webp_quality' => 80,
    'gallery_page_size' => filter_var(env('FAMIE_GALLERY_PAGE_SIZE', 30), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 30,
    'register_window_seconds' => (int) env('FAMIE_REGISTER_WINDOW_SECONDS', 600),
    'register_max_attempts' => (int) env('FAMIE_REGISTER_MAX_ATTEMPTS', 5),
    'register_cooldown_seconds' => (int) env('FAMIE_REGISTER_COOLDOWN_SECONDS', 600),
];
