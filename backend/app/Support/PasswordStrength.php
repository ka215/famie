<?php

namespace App\Support;

class PasswordStrength
{
    public const MESSAGE = 'パスワードは15文字以上・72バイト以内で、よく使われる語や単純な繰り返しを避けてください。';

    public static function acceptable(string $password): bool
    {
        if (mb_strlen($password) < 15 || strlen($password) > 72 || preg_match('/\p{Cc}/u', $password)) {
            return false;
        }

        $compact = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($password));
        if (! $compact || preg_match('/^(.{1,16})\1+$/u', $compact)
            || preg_match('/^(password|qwerty|letmein|welcome|iloveyou|admin|famie|パスワード)[0-9]*$/u', $compact)) {
            return false;
        }
        foreach (['0123456789', '9876543210', 'abcdefghijklmnopqrstuvwxyz', 'zyxwvutsrqponmlkjihgfedcba', 'qwertyuiop'] as $sequence) {
            if (str_contains(str_repeat($sequence, 8), $compact)) {
                return false;
            }
        }

        return true;
    }
}
