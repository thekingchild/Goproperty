<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
 * P00-07: the organization model, membership, money casting and the
 * activity log wiring (PLATFORM_CONSOLE.md § 7).
 */

it('casts enums, money and encrypted fields', function () {
    $organization = Organization::factory()->create([
        'type' => OrganizationType::Hybrid,
        'online_payment_cap_kobo' => Money::naira(5_000_000),
    ]);
    $organization->cac_number = 'RC-1234567';
    $organization->save();

    $fresh = $organization->fresh();

    expect($fresh->type)->toBe(OrganizationType::Hybrid)
        ->and($fresh->status)->toBe(OrganizationStatus::Active)
        ->and($fresh->transaction_tier)->toBe(TransactionTier::One)
        ->and($fresh->online_payment_cap_kobo?->kobo)->toBe(500_000_000)
        ->and($fresh->cac_number)->toBe('RC-1234567')
        ->and(DB::table('organizations')->value('cac_number'))->not->toContain('1234567')
        ->and(DB::table('organizations')->value('online_payment_cap_kobo'))->toBe(500_000_000);
});

it('refuses a plain number for a kobo column', function () {
    Organization::factory()->create(['online_payment_cap_kobo' => 50_000]);
})->throws(InvalidArgumentException::class, 'Set online_payment_cap_kobo with App\Support\Money');

it('knows its members', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $member->organizations()->attach($organization, ['is_default' => true]);

    expect($member->belongsToOrganization($organization))->toBeTrue()
        ->and($outsider->belongsToOrganization($organization))->toBeFalse()
        ->and($organization->users()->pluck('users.id')->all())->toBe([$member->id]);
});

it('routes by slug', function () {
    expect(Organization::factory()->make(['slug' => 'acme'])->getRouteKey())->toBe('acme');
});

it('logs changes, but never the CAC number', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $organization = Organization::factory()->create(['name' => 'Acme Homes']);

    $organization->update(['name' => 'Acme Homes Ltd', 'status' => OrganizationStatus::Suspended]);
    $organization->cac_number = 'RC-7654321';
    $organization->save();

    $entries = Activity::query()->where('log_name', 'organization')->orderBy('id')->get();
    $update = $entries->firstWhere('event', 'updated');

    expect($entries->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($update->causer?->is($user))->toBeTrue()
        ->and($update->subject?->is($organization))->toBeTrue()
        ->and($update->attribute_changes['attributes'] ?? [])->toMatchArray(['name' => 'Acme Homes Ltd', 'status' => 'suspended'])
        ->and(DB::table('activity_log')->get()->toJson())->not->toContain('7654321')
        ->and($organization->toJson())->not->toContain('7654321');
});
