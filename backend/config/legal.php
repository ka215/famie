<?php

return [
    'manifest_path' => resource_path('legal-documents.json'),
    'storage_enabled' => env('FAMIE_LEGAL_STORAGE_ENABLED', false),
    'acceptance_enabled' => env('FAMIE_LEGAL_ACCEPTANCE_ENABLED', false),
    'accepted_versions' => [
        'terms' => ['0.1.0'],
        'privacy' => ['0.1.0'],
    ],
];
