<?php

namespace App\Rules;

use App\Support\ReservedUsernames;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class AvailableUsername implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (app(ReservedUsernames::class)->contains($value)) {
            $fail(__('messages.username_unavailable'));
        }
    }
}
