<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class UsernameRules
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9._-]{1,48}[a-z0-9])$/';

    /** @return array<int, mixed> */
    public static function create(): array
    {
        return ['required', 'string', 'min:3', 'max:50', 'regex:'.self::PATTERN, Rule::unique('users', 'username')];
    }

    /** @return array<int, mixed> */
    public static function syntax(): array
    {
        return ['required', 'string', 'min:3', 'max:50', 'regex:'.self::PATTERN];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'username.required' => __('messages.username_required'),
            'username.string' => __('messages.username_string'),
            'username.min' => __('messages.username_min'),
            'username.max' => __('messages.username_max'),
            'username.regex' => __('messages.username_format'),
            'username.unique' => __('messages.username_taken'),
        ];
    }
}
