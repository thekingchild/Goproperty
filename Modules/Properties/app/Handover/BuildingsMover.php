<?php

declare(strict_types=1);

namespace Modules\Properties\Handover;

use App\Models\User;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\MoveDirection;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Str;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Properties\OwnerPortal\OwnerLinks;

/**
 * The Properties module's part of a management handover
 * (BILLING_PAYMENTS.md § 7.4; App\Tenancy\Moves): the buildings, with
 * their units (archived ones too: they are the building's), move to the
 * other organization; their photos follow (App\Media\MediaMover).
 *
 * - A moved building keeps its web address (slug) there when it is free,
 *   else takes the next free one ("palm-court-2"); on a return the address
 *   comes from its name, so it usually gets back the one it had. An
 *   import reference (`external_ref`) of a building or unit that is taken
 *   there is cleared on the moved row; the import batch is cleared (the
 *   receiving organization cannot roll the old one back).
 * - Agent assignments on the moved buildings are dropped: they belong to
 *   the old organization's members.
 * - Handover: the landlord becomes the buildings' owner there: their owner
 *   profile already linked to their user, else one with their email, else
 *   a new one from the landlord organization's details; linked to their
 *   user with OwnerLinks::link() (`property-owner` there, P03-15), so they
 *   follow the buildings in /owner.
 * - Return: each building gets back the owner profile it had before the
 *   handover (when it still exists); the landlord's link in the agency's
 *   organization is removed with OwnerLinks::unlink() unless they own other
 *   buildings there.
 */
final readonly class BuildingsMover implements MovesBuildingRecords
{
    public function __construct(
        private OrganizationContext $context,
        private Properties $properties,
        private OwnerLinks $links,
    ) {}

    public function order(): int
    {
        return 10;
    }

    public function move(BuildingMove $move): void
    {
        $buildings = Property::query()->whereKey($move->propertyIds)->orderBy('id')->lockForUpdate()->get();

        if ($buildings->count() !== count($move->propertyIds)) {
            throw new BuildingMoveFailed("A building of this handover is no longer in {$move->from->name}.");
        }

        if ($move->direction === MoveDirection::Handover && $buildings->contains(fn (Property $building): bool => $building->status !== PropertyStatus::Active)) {
            throw new BuildingMoveFailed('Only active buildings are handed over: one of them was archived meanwhile.');
        }

        $move->count('agent_assignments_dropped', AgentAssignment::query()->whereIn('property_id', $move->propertyIds)->delete());

        $ownerId = $move->direction === MoveDirection::Handover ? $this->landlordOwnerIn($move) : null;

        foreach ($buildings as $building) {
            [$slug, $refTaken] = $this->context->run($move->to, fn (): array => [
                // A return takes the address the name gives (usually the one it had) when free.
                $this->properties->freeSlug($move->direction === MoveDirection::Return ? $building->name : $building->slug),
                $building->external_ref !== null && Property::query()->withTrashed()->where('external_ref', $building->external_ref)->exists(),
            ]);

            $move->setPreviousOwnerProfileId($building->id, $building->owner_profile_id);
            $move->count('external_refs_cleared', $refTaken ? 1 : 0);
            $move->count('slugs_changed', $slug !== $building->slug ? 1 : 0);
            $move->reassign(Property::class, [$building->id], [
                'slug' => $slug,
                'external_ref' => $refTaken ? null : $building->external_ref,
                'owner_profile_id' => $move->direction === MoveDirection::Handover ? $ownerId : $this->restoredOwner($move, $building->id),
                'import_batch_id' => null,
            ]);
        }

        $this->moveUnits($move);

        if ($move->direction === MoveDirection::Return) {
            $this->unlinkLandlord($move);
        }
    }

    private function moveUnits(BuildingMove $move): void
    {
        $units = Unit::query()->withTrashed()->whereIn('property_id', $move->propertyIds)->orderBy('id')->get(['id', 'external_ref']);
        $refs = $units->pluck('external_ref')->filter()->values()->all();
        $taken = $refs === [] ? [] : $this->context->run($move->to, static fn (): array => Unit::query()->withTrashed()->whereIn('external_ref', $refs)->pluck('external_ref')->all());

        [$clashing, $clear] = $units->partition(static fn (Unit $unit): bool => $unit->external_ref !== null && in_array($unit->external_ref, $taken, true));

        $move->reassign(Unit::class, $clear->modelKeys(), ['import_batch_id' => null], withTrashed: true);
        $move->reassign(Unit::class, $clashing->modelKeys(), ['import_batch_id' => null, 'external_ref' => null], withTrashed: true);
        $move->count('external_refs_cleared', $clashing->count());
    }

    /** The landlord's owner profile in the receiving organization, linked to their user (made when missing). */
    private function landlordOwnerIn(BuildingMove $move): int
    {
        return $this->context->run($move->to, function () use ($move): int {
            $landlord = $move->landlord;
            $owner = $landlord !== null ? OwnerProfile::query()->withTrashed()->where('user_id', $landlord->id)->first() : null;

            if ($owner === null && $landlord?->email !== null) {
                $owner = OwnerProfile::query()->whereNull('user_id')->where('email', Str::lower($landlord->email))->orderBy('id')->first();
            }

            if ($owner === null) {
                $organization = $move->landlordOrganization;
                $owner = OwnerProfile::query()->create([
                    'type' => OwnerType::Company,
                    'company_name' => Str::limit($organization->name, 160, ''),
                    'email' => $landlord?->email !== null ? Str::lower($landlord->email) : null,
                    'phone' => $landlord?->phone,
                    'address' => $organization->address !== null ? Str::limit($organization->address, 300, '') : null,
                    'state' => $organization->state,
                    'lga' => $organization->lga,
                    'notes' => __('Added when :organization handed its buildings over.', ['organization' => $organization->name]),
                ]);
                $move->count('owner_profiles_created');
            } elseif ($owner->trashed()) {
                $owner->restore();
            }

            if ($landlord !== null) {
                $this->links->link($owner, $landlord, $move->to);
            }

            $move->setOwnerProfileId($owner->id);

            return $owner->id;
        });
    }

    /** On a return: the owner profile the building had in the landlord's organization, if it is still there. */
    private function restoredOwner(BuildingMove $move, int $propertyId): ?int
    {
        $ownerId = $move->restoreOwnerProfileIds[$propertyId] ?? null;

        if ($ownerId === null) {
            return null;
        }

        return $this->context->run($move->to, static fn (): bool => OwnerProfile::query()->whereKey($ownerId)->exists()) ? $ownerId : null;
    }

    /** On a return: the landlord no longer follows buildings here unless they own others. */
    private function unlinkLandlord(BuildingMove $move): void
    {
        if ($move->landlordOwnerProfileId === null) {
            return;
        }

        $owner = OwnerProfile::query()->withTrashed()->find($move->landlordOwnerProfileId);

        if ($owner === null || $owner->user_id === null || Property::query()->where('owner_profile_id', $owner->id)->exists()) {
            return;
        }

        $this->links->unlink($owner, User::query()->find($owner->user_id), $move->from);
        $move->count('owner_links_removed');
    }
}
