<?php

declare(strict_types=1);

namespace App\Privacy\Erasure;

use App\Models\User;
use App\Privacy\Data\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sign-in accounts after an erasure or a purge (P09-02; default 19). An
 * account is a platform record shared by every organization the person
 * deals with, so it is anonymised only when nothing else uses it: no
 * workspace membership, tenant record or owner record left anywhere but
 * the one being erased. Then its name, email, phone, password, two-factor
 * secrets, passkeys, sessions, trusted devices, notifications, sign-in log
 * and reset tokens go, and its consent records keep only what was agreed
 * and when. The row stays (with no way to sign in), because records keep
 * pointing at it. Otherwise it is left as it is: the person still uses it.
 *
 * Email suppressions (DATABASE_SCHEMA.md "email_suppressions": the NDPA
 * tooling lifts and blanks the address) follow the same rule: an address
 * nobody uses any more is lifted and blanked.
 */
final class AccountAnonymiser
{
    public function anonymiseIfUnused(int $userId, ?Person $person = null): bool
    {
        if (! $this->unused($userId, $person)) {
            return false;
        }

        $this->anonymise($userId);

        return true;
    }

    /** Whether nothing but the person being erased (if any) still uses the account. */
    public function unused(int $userId, ?Person $person = null): bool
    {
        if (DB::table('organization_user')->where('user_id', $userId)->exists()) {
            return false;
        }

        if (DB::table('owner_profiles')->where('user_id', $userId)->exists()) {
            return false;
        }

        return ! DB::table('tenant_profiles')->where('user_id', $userId)
            ->when($person !== null, fn ($query) => $query->where('id', '!=', $person->tenantProfileId))
            ->exists();
    }

    public function anonymise(int $userId): void
    {
        $email = DB::table('users')->where('id', $userId)->value('email');

        DB::table('users')->where('id', $userId)->update([
            'name' => 'Erased user',
            'email' => null,
            'phone' => null,
            'phone_verified_at' => null,
            'phone_verification_method' => null,
            'phone_confirmed_by_user_id' => null,
            'preferences' => null,
            'email_verified_at' => null,
            // Nobody knows this password: the account can never sign in again.
            'password' => Hash::make(Str::random(64)),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'updated_at' => now(),
        ]);

        DB::table('passkeys')->where('user_id', $userId)->delete();
        DB::table('sessions')->where('user_id', $userId)->delete();
        DB::table('user_devices')->where('user_id', $userId)->delete();
        DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->where('notifiable_id', $userId)->delete();
        DB::table('authentication_logs')->where('authenticatable_type', (new User)->getMorphClass())->where('authenticatable_id', $userId)->delete();
        DB::table('consent_records')->where('user_id', $userId)->update(['ip_address' => null, 'user_agent' => null]);

        if (is_string($email) && $email !== '') {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }
    }

    /**
     * Lifts and blanks the suppressions of addresses nothing uses any more.
     *
     * @param  list<string>  $emails
     */
    public function liftSuppressions(array $emails): int
    {
        $lifted = 0;

        foreach (array_unique(array_map('mb_strtolower', $emails)) as $email) {
            $used = DB::table('users')->where('email', $email)->exists()
                || DB::table('tenant_profiles')->where('email', $email)->exists()
                || DB::table('owner_profiles')->where('email', $email)->exists();

            if ($used) {
                continue;
            }

            foreach (DB::table('email_suppressions')->where('email', $email)->pluck('id') as $id) {
                $lifted += DB::table('email_suppressions')->where('id', $id)->update([
                    'email' => 'erased-'.$id.'@erased.invalid',
                    'detail' => null,
                    'updated_at' => now(),
                ]);
                DB::table('email_suppressions')->where('id', $id)->whereNull('lifted_at')->update(['lifted_at' => now()]);
            }
        }

        return $lifted;
    }
}
