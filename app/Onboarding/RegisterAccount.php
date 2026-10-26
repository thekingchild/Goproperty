<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Notifications\SignupForExistingAccount;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The account step of signup (ONBOARDING_COMPLIANCE.md § 2 step 1), shaped
 * so the reply never reveals whether an account exists (UX_DESIGN.md
 * § 3.1): every valid form ends on the same "check your email" page.
 *
 * - New email: an unverified account is created and the verification link
 *   sent (Laravel's Registered listener). If the phone number already
 *   belongs to another account, the account is created without it and the
 *   organization step asks for a number again, once the email is verified.
 * - Email that already has an account: nothing changes on it; its owner
 *   gets an email saying so, with sign-in and reset links. The password is
 *   still hashed, so both paths take about as long.
 */
final readonly class RegisterAccount
{
    public function __construct(
        private CreateNewUser $creator,
        private Hasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return User|null the new account, or null when the email was taken
     */
    public function __invoke(array $input): ?User
    {
        $data = $this->creator->validate($input);

        $existing = User::query()->where('email', $data['email'])->first();

        if ($existing !== null) {
            $this->hasher->make($data['password']);
            $existing->notify(new SignupForExistingAccount);

            return null;
        }

        $phoneTaken = User::query()->where('phone', $data['phone'])->exists();

        try {
            $user = DB::transaction(fn (): User => User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $phoneTaken ? null : $data['phone'],
                'password' => $data['password'],
            ]));
        } catch (UniqueConstraintViolationException) {
            // Two signups for the same address at once: the other one won.
            User::query()->where('email', $data['email'])->first()?->notify(new SignupForExistingAccount);

            return null;
        }

        event(new Registered($user));

        return $user;
    }
}
