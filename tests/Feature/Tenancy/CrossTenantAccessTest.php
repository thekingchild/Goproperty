<?php

declare(strict_types=1);

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Settings\SettingType;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Tests\Support\OrganizationScopedModels;

/*
 * P01-01 acceptance: data never crosses organizations. Every
 * organization-owned model (the dataset in Tests\Support\OrganizationScopedModels),
 * plus role assignments and organization settings, is probed from a second
 * organization (ARCHITECTURE.md § 4, IMPLEMENTATION_PLAN.md P01 exit).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->context = app(OrganizationContext::class);
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

/**
 * One record in each organization.
 *
 * @param  class-string<Model>  $class
 * @return array{0: Model, 1: Model}
 */
function recordsInBoth(string $class, Organization $acme, Organization $other): array
{
    $context = app(OrganizationContext::class);

    return [
        $context->run($acme, fn () => OrganizationScopedModels::make($class)),
        $context->run($other, fn () => OrganizationScopedModels::make($class)),
    ];
}

it('lists every organization-owned model in the probe dataset', function () {
    $owned = [];

    foreach (['App\\' => app_path(), 'Modules\\' => base_path('Modules')] as $namespace => $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains((string) file_get_contents($file->getPathname()), 'BelongsToOrganization')) {
                continue;
            }

            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));

            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                $owned[] = $class;
            }
        }
    }

    expect($owned)->not->toBeEmpty()
        ->and(array_values(array_diff($owned, array_keys(OrganizationScopedModels::factories()))))->toBe([]);
});

it('refuses to read or write without an organization', function (string $class) {
    recordsInBoth($class, $this->acme, $this->other);

    expect(fn () => $class::query()->count())->toThrow(MissingOrganizationContext::class)
        ->and(fn () => OrganizationScopedModels::make($class))->toThrow(MissingOrganizationContext::class);
})->with('organization-scoped models');

it('shows each organization only its own rows', function (string $class) {
    [$own, $foreign] = recordsInBoth($class, $this->acme, $this->other);

    $this->context->run($this->acme, function () use ($class, $own, $foreign) {
        expect($class::query()->pluck('id')->all())->toBe([$own->getKey()])
            ->and($class::query()->find($foreign->getKey()))->toBeNull()
            ->and($class::query()->whereKey($foreign->getKey())->exists())->toBeFalse();
    });
})->with('organization-scoped models');

it('cannot change or delete another organization\'s rows', function (string $class) {
    [, $foreign] = recordsInBoth($class, $this->acme, $this->other);
    $before = $this->context->run($this->other, fn () => $class::query()->findOrFail($foreign->getKey())->getAttributes());

    $this->context->run($this->acme, function () use ($class, $foreign) {
        $class::query()->whereKey($foreign->getKey())->update(['updated_at' => '2001-01-01 00:00:00']);
        $class::query()->update(['updated_at' => '2001-01-01 00:00:00']);
        $class::query()->whereKey($foreign->getKey())->delete();
    });

    expect($this->context->run($this->other, fn () => $class::query()->find($foreign->getKey())?->getAttributes()))->toBe($before);
})->with('organization-scoped models');

it('cannot create a row in, or move a row to, another organization', function (string $class) {
    [$own] = recordsInBoth($class, $this->acme, $this->other);

    $this->context->run($this->acme, function () use ($own) {
        // forceFill: the probe must not depend on organization_id being guarded.
        expect(fn () => $own->replicate()->forceFill(['organization_id' => $this->other->id])->save())
            ->toThrow(CrossOrganizationWrite::class);

        $own->setAttribute('organization_id', $this->other->id);
        expect(fn () => $own->save())->toThrow(CrossOrganizationWrite::class);
    });
})->with('organization-scoped models');

it('answers 404 for another organization\'s row bound from the URL', function (string $class) {
    Route::middleware(['web', 'auth', 'organization'])
        ->get('o/{organization}/cross-tenant-probe/{id}', fn (Organization $organization, string $id) => $class::query()->findOrFail($id)->getKey());

    [$own, $foreign] = recordsInBoth($class, $this->acme, $this->other);
    $user = joinAs(User::factory()->create(), $this->acme);
    joinAs($user, $this->other, OrganizationRole::Agent);

    $this->actingAs($user)->get("/o/acme-homes/cross-tenant-probe/{$own->getKey()}")->assertOk();
    $this->actingAs($user)->get("/o/acme-homes/cross-tenant-probe/{$foreign->getKey()}")->assertNotFound();
})->with('organization-scoped models');

it('lists only the current organization\'s members', function () {
    $both = joinAs(User::factory()->create(), $this->acme);
    joinAs($both, $this->other, OrganizationRole::Agent);
    $acmeOnly = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $otherOnly = joinAs(User::factory()->create(), $this->other);

    $members = fn (Organization $organization) => $this->context->run($organization, fn () => Membership::query()->orderBy('user_id')->pluck('user_id')->all());

    expect($members($this->acme))->toBe([$both->id, $acmeOnly->id])
        ->and($members($this->other))->toBe([$both->id, $otherOnly->id]);
});

it('keeps a user\'s roles and permissions to the organization they were given in', function () {
    $user = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Owner);
    joinAs($user, $this->other, OrganizationRole::Agent);

    $this->context->run($this->acme, function () use ($user) {
        expect($user->getRoleNames()->all())->toBe(['org-owner'])
            ->and(Gate::forUser($user)->allows(OrganizationPermission::ManageBilling->value))->toBeTrue();
    });

    $this->context->run($this->other, function () use ($user) {
        expect($user->getRoleNames()->all())->toBe(['agent'])
            ->and($user->hasRole('org-owner'))->toBeFalse()
            ->and(Gate::forUser($user)->allows(OrganizationPermission::ManageBilling->value))->toBeFalse()
            ->and(Gate::forUser($user)->allows(OrganizationPermission::ConfirmPayments->value))->toBeTrue();
    });

    // Outside any organization a customer holds no role at all.
    expect($user->fresh()?->getRoleNames()->all())->toBe([])
        ->and(Gate::forUser($user)->allows(OrganizationPermission::ManageBilling->value))->toBeFalse();
});

it('keeps a role one organization defined to itself', function () {
    $custom = $this->context->run($this->other, fn () => Role::create(['name' => 'night-watch', 'guard_name' => 'web']));
    $user = joinAs(User::factory()->create(), $this->acme);

    expect($custom->organization_id)->toBe($this->other->id);

    $this->context->run($this->acme, function () use ($user, $custom) {
        expect(fn () => Role::findByName('night-watch', 'web'))->toThrow(RoleDoesNotExist::class)
            ->and(fn () => $user->assignRole($custom))->toThrow(CrossOrganizationWrite::class, 'another organization defined');
    });

    expect($user->roleNamesIn($this->acme))->toBe(['org-owner']);
});

it('keeps organization settings apart', function () {
    app(SettingsRegistry::class)->define(new SettingDefinition('probe.reminder_days', SettingType::Integer, 7, ['integer'], SettingScope::Organization));
    $settings = app(SettingsStore::class);

    $this->context->run($this->acme, fn () => $settings->set('probe.reminder_days', 3));

    expect($this->context->run($this->other, fn () => $settings->get('probe.reminder_days')))->toBe(7)
        ->and($this->context->run($this->acme, fn () => $settings->get('probe.reminder_days')))->toBe(3);
});
