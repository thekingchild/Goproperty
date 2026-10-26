<?php

declare(strict_types=1);

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run on the application test case with a fresh database.
| Unit tests stay framework-free unless a file opts in.
|
| Drills start real `php artisan` processes, which cannot see rows inside
| a test transaction, so they commit and truncate instead.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('drill')
    ->in('Drill');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A platform staff member. Staff must use an authenticator app (P00-10c),
 * so by default the account has 2FA set up and can open console pages.
 *
 * @param  array<string, mixed>  $attributes
 */
function staffMember(array $attributes = [], bool $withTwoFactor = true): PlatformStaff
{
    $staff = PlatformStaff::query()->create($attributes + [
        'name' => 'Ada',
        'email' => 'ada@goproperty.ng',
        'password' => 'x-long-password-1',
        'status' => StaffStatus::Active,
    ]);

    if ($withTwoFactor) {
        $staff->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    }

    return $staff;
}
