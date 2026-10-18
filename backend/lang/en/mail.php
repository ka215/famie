<?php

return [
    'verify' => [
        'title' => 'Verify your email address',
        'body' => 'Requesting login ID: :username. Only press the confirmation button on the linked page if you requested this for your own account. This link expires in :minutes minutes.',
    ],
    'email_changed' => ['title' => 'Email address changed', 'body' => 'Your account email address has been changed.'],
    'reset' => ['title' => 'Reset your password', 'body' => 'Set a new password on the linked page. This link expires in :minutes minutes.'],
    'reset_complete' => ['title' => 'Password reset complete', 'body' => 'Your password has been reset. You need to sign in again on all devices.'],
    'ignore_link' => 'If you did not request this email, do not use the link and discard this message.',
    'unrecognized_action' => 'If you do not recognize this action, reset your password from the login page.',
    'rights' => 'All rights reserved.',
];
