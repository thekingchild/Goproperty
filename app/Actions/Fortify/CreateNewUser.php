<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Rules\NigerianMobile;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * The signup account step (ONBOARDING_COMPLIANCE.md § 2 step 1): name,
 * email, Nigerian mobile number (stored as +234…, never sent an SMS code)
 * and a password under the customer password rule (UX_DESIGN.md § 3.1: at
 * least 10 characters and, in production, not on a breached-password list).
 *
 * There is no "already taken" rule: App\Onboarding\RegisterAccount decides
 * what happens to an address or number that already has an account, so the
 * form never reveals one.
 */
class CreateNewUser implements CreatesNewUsers
{
    use ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, phone: string, password: string}
     */
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32', new NigerianMobile],
            'password' => ['required', 'string', 'max:255', Password::default()],
        ], attributes: ['phone' => 'mobile number'])->validate();

        return [
            'name' => trim((string) $data['name']),
            'email' => Str::lower(trim((string) $data['email'])),
            'phone' => (string) PhoneNumber::nigerianMobile((string) $data['phone']),
            'password' => (string) $data['password'],
        ];
    }

    /**
     * A new, unverified account. App\Onboarding\RegisterAccount calls
     * validate() and creates the account itself, after checking that the
     * email and phone number are free.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        return User::query()->create($this->validate($input));
    }
}
