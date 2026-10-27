<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\PropertyType;

require_once __DIR__.'/helpers.php';

/*
 * P02-01b: Modules\Properties\Contracts\PropertyDirectory, how other modules
 * read buildings (ARCHITECTURE.md § 6.2, § 8 rule 3): the current
 * organization's buildings only, archived ones marked, rolled-back ones gone.
 */

beforeEach(function () {
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('answers for the current organization only', function () {
    $landlord = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'owner_profile_id' => $landlord->id, 'property_type' => PropertyType::Duplex, 'state' => 'Lagos']);
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'status' => PropertyStatus::Archived]);
    $rolledBack = buildingIn($this->acme, ['name' => 'Imported Court']);
    inOrganization($this->acme, fn () => $rolledBack->delete());
    $foreign = buildingIn($this->other, ['name' => 'Foreign Court']);

    inOrganization($this->acme, function () use ($palm, $cedar, $rolledBack, $foreign): void {
        $directory = app(PropertyDirectory::class);
        $summary = $directory->find($palm->id);

        expect($summary)->toBeInstanceOf(PropertySummary::class)
            ->and($summary->name)->toBe('Palm Court')
            ->and($summary->slug)->toBe('palm-court')
            ->and($summary->type)->toBe('duplex')
            ->and($summary->typeLabel)->toBe('Duplex')
            ->and($summary->state)->toBe('Lagos')
            ->and($summary->archived)->toBeFalse()
            ->and($summary->ownerName)->toBe('Tunde Bakare')
            ->and($summary->organizationId)->toBe($this->acme->id)
            ->and($directory->find($cedar->id)?->archived)->toBeTrue()
            ->and($directory->find($rolledBack->id))->toBeNull()
            ->and($directory->find($foreign->id))->toBeNull()
            ->and(array_keys($directory->findMany([$palm->id, $cedar->id, $foreign->id, 999999])))->toEqualCanonicalizing([$palm->id, $cedar->id])
            ->and($directory->findMany([]))->toBe([])
            ->and($directory->isActive($palm->id))->toBeTrue()
            ->and($directory->isActive($cedar->id))->toBeFalse()
            ->and($directory->isActive($foreign->id))->toBeFalse()
            ->and($directory->activeOptions())->toBe([$palm->id => 'Palm Court'])
            ->and($directory->countActive())->toBe(1);
    });

    expect(inOrganization($this->other, fn () => app(PropertyDirectory::class)->activeOptions()))->toBe([$foreign->id => 'Foreign Court']);
});

it('refuses to answer without an organization', function () {
    buildingIn($this->acme);

    expect(fn () => app(PropertyDirectory::class)->countActive())->toThrow(MissingOrganizationContext::class);
});
