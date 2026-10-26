<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Nigerian mobile number in any usual spelling (PhoneNumber::nigerianMobile). */
final class NigerianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::nigerianMobile($value) === null) {
            $fail('Enter a Nigerian mobile number, such as 0803 123 4567.');
        }
    }
}
