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
            'username.required' => 'ログインIDを入力してください。',
            'username.string' => 'ログインIDは文字列で入力してください。',
            'username.min' => 'ログインIDは3文字以上で入力してください。',
            'username.max' => 'ログインIDは50文字以内で入力してください。',
            'username.regex' => 'ログインIDは半角小文字英字・数字・ピリオド・ハイフン・アンダースコアを使用し、先頭と末尾は英数字にしてください。',
            'username.unique' => 'このログインIDは既に使用されています。',
        ];
    }
}
