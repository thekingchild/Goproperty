<?php

declare(strict_types=1);

namespace Modules\Properties\Units;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Events\UnitCreated;
use Modules\Properties\Events\UnitDeleted;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * The only writer of units (DATABASE_SCHEMA.md § 3 `units`), with
 * CreateUnitsInBulk built on it: add, edit, archive and restore, each
 * checked against UnitPolicy for the acting user (so the suspension and
 * impersonation guards apply), and the status Leasing sets through
 * Contracts\UnitDirectory. Every change is in the audit log (log
 * `properties`, the unit as subject).
 *
 * A label is unique in its building, archived units included (the unique
 * index covers them): adding a unit with an archived unit's label brings
 * that unit back with the new details, audited as restored (P02 decision 3).
 * Archiving is a soft delete, so the unit stops counting towards the
 * GoProperty bill (BILLING_PAYMENTS.md § 1.4); an occupied or reserved
 * unit is not archived. UnitCreated and UnitDeleted are sent after the
 * transaction commits (P06 metering).
 */
final readonly class Units
{
    public const string LOG = 'properties';

    /** Fields compared for the audit entry, by column. */
    private const array AUDITED = ['unit_label', 'unit_type', 'bedrooms', 'bathrooms', 'size_sqm', 'floor', 'base_rent_kobo', 'service_charge_kobo', 'status', 'description'];

    public function __construct(private UnitDetails $details) {}

    /**
     * @param  array<string, mixed>  $input  unitLabel plus UnitDetails' keys
     *
     * @throws ValidationException
     */
    public function create(User $by, Property $property, array $input): Unit
    {
        Gate::forUser($by)->authorize('create', [Unit::class, $property]);

        $label = UnitDetails::label($input['unitLabel'] ?? null);
        $values = $this->details->validated($input, null, ['unitLabel' => $label], ['unitLabel' => UnitDetails::labelRules()], ['unitLabel' => 'unit label']);
        $label = (string) $label;

        try {
            return DB::transaction(function () use ($by, $property, $label, $values): Unit {
                $building = $this->lockBuilding($property);
                $taken = $this->existing($building, [$label])[mb_strtolower($label)] ?? null;

                if ($taken !== null && ! $taken->trashed()) {
                    throw ValidationException::withMessages(['unitLabel' => self::takenMessage($taken->unit_label, $building)]);
                }

                return $this->place($by, $building, $label, $values, $taken);
            });
        } catch (UniqueConstraintViolationException) {
            // Someone added the same label a moment ago.
            throw ValidationException::withMessages(['unitLabel' => self::takenMessage($label, $property)]);
        }
    }

    /**
     * @param  array<string, mixed>  $input  as for create()
     *
     * @throws ValidationException
     */
    public function update(User $by, Unit $unit, array $input): Unit
    {
        Gate::forUser($by)->authorize('update', $unit);

        $label = UnitDetails::label($input['unitLabel'] ?? null);
        $values = $this->details->validated($input, $unit, ['unitLabel' => $label], ['unitLabel' => UnitDetails::labelRules()], ['unitLabel' => 'unit label']);
        $label = (string) $label;

        try {
            return DB::transaction(function () use ($by, $unit, $label, $values): Unit {
                $unit = Unit::query()->with('property')->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
                $building = $unit->property;
                abort_unless($building instanceof Property, 404);

                if (mb_strtolower($label) !== mb_strtolower($unit->unit_label)) {
                    $taken = Unit::query()->withTrashed()->where('property_id', $unit->property_id)->where('unit_label', $label)->whereKeyNot($unit->getKey())->first();

                    if ($taken !== null) {
                        throw ValidationException::withMessages(['unitLabel' => $taken->trashed()
                            ? __(':label is an archived unit of :building. Restore it instead, or choose another label.', ['label' => $taken->unit_label, 'building' => $building->name])
                            : self::takenMessage($taken->unit_label, $building)]);
                    }
                }

                $before = $unit->status;
                $unit->fill($values);
                $unit->unit_label = $label;

                if (isset($values['status'])) {
                    $unit->status = $values['status'];
                }

                $changed = array_values(array_intersect(self::AUDITED, array_keys($unit->getDirty())));

                if ($changed !== []) {
                    $unit->save();
                    $this->audit('unit.updated', $unit, $by, 'Unit changed', ['changed' => $changed]
                        + ($before !== $unit->status ? ['status_from' => $before->value, 'status_to' => $unit->status->value] : []));
                }

                return $unit;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['unitLabel' => self::takenMessage($label, $unit->property)]);
        }
    }

    /**
     * Archive the unit: a soft delete, so it stops counting towards the bill.
     *
     * @throws ValidationException (key `archive`) while a lease holds the unit
     */
    public function archive(User $by, Unit $unit): void
    {
        Gate::forUser($by)->authorize('delete', $unit);

        DB::transaction(function () use ($by, $unit): void {
            $unit = Unit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            if ($unit->status->heldByLease()) {
                throw ValidationException::withMessages(['archive' => __(':label is :status. End its lease before archiving it.', [
                    'label' => $unit->unit_label,
                    'status' => mb_strtolower(__($unit->status->label())),
                ])]);
            }

            $unit->delete();
            $this->audit('unit.archived', $unit, $by, 'Unit archived');
            UnitDeleted::dispatch($unit->organization_id, $unit->property_id, $unit->id);
        });
    }

    public function restore(User $by, Unit $unit): void
    {
        Gate::forUser($by)->authorize('restore', $unit);

        DB::transaction(function () use ($by, $unit): void {
            $unit = Unit::query()->withTrashed()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            if (! $unit->trashed()) {
                return;
            }

            $unit->restore();
            $this->audit('unit.restored', $unit, $by, 'Unit restored');
            UnitCreated::dispatch($unit->organization_id, $unit->property_id, $unit->id, restored: true);
        });
    }

    /**
     * The status set by Leasing (occupied, reserved, vacant again) through
     * Contracts\UnitDirectory: no user policy here (the caller checked its
     * own), audited with the reason given. Archived units are not found.
     */
    public function setStatus(int $unitId, UnitStatus $status, string $reason): Unit
    {
        return DB::transaction(function () use ($unitId, $status, $reason): Unit {
            $unit = Unit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();
            $before = $unit->status;

            if ($before !== $status) {
                $unit->status = $status;
                $unit->save();
                $this->audit('unit.status_changed', $unit, null, 'Unit status changed', ['status_from' => $before->value, 'status_to' => $status->value, 'reason' => $reason]);
            }

            return $unit;
        });
    }

    /**
     * The building, locked until the transaction ends (serialises adding
     * units to it, and archiving it), checked to be still open for units.
     *
     * @internal for this module's writers, inside their transaction
     */
    public function lockBuilding(Property $property): Property
    {
        $building = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

        if ($building->isArchived()) {
            throw ValidationException::withMessages(['building' => __(':name is archived. Restore it before adding units.', ['name' => $building->name])]);
        }

        return $building;
    }

    /**
     * The building's units with these labels, archived ones included, by
     * lower-case label (labels compare without case, as the unique index does).
     *
     * @internal
     *
     * @param  list<string>  $labels
     * @return array<string, Unit>
     */
    public function existing(Property $building, array $labels): array
    {
        $found = [];

        foreach (array_chunk($labels, 500) as $chunk) {
            foreach (Unit::query()->withTrashed()->where('property_id', $building->id)->whereIn('unit_label', $chunk)->lockForUpdate()->get() as $unit) {
                $found[mb_strtolower($unit->unit_label)] = $unit;
            }
        }

        return $found;
    }

    /**
     * Add the unit with this label, or bring back the archived one that has
     * it, with these details. Inside the caller's transaction, after
     * lockBuilding() and existing().
     *
     * @internal
     *
     * @param  array<string, mixed>  $values  UnitDetails::validated()
     * @param  array<string, mixed>  $audit  more audit properties
     */
    public function place(User $by, Property $building, string $label, array $values, ?Unit $archived, array $audit = []): Unit
    {
        if ($archived !== null) {
            $archived->fill($values);
            $archived->unit_label = $label;
            $archived->status = $values['status'] ?? UnitStatus::Vacant;
            $archived->restore();
            $this->audit('unit.restored', $archived, $by, 'Unit restored', ['readded' => true, ...$audit]);
            UnitCreated::dispatch($archived->organization_id, $archived->property_id, $archived->id, restored: true);

            return $archived;
        }

        $unit = new Unit($values);
        $unit->property_id = $building->id;
        $unit->unit_label = $label;
        $unit->status = $values['status'] ?? UnitStatus::Vacant;
        $unit->save();

        $this->audit('unit.created', $unit, $by, 'Unit added', $audit);
        UnitCreated::dispatch($unit->organization_id, $unit->property_id, $unit->id);

        return $unit;
    }

    public static function takenMessage(string $label, ?Property $building): string
    {
        return __(':label already exists in :building.', ['label' => $label, 'building' => $building->name ?? __('this building')]);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Unit $unit, ?User $by, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($unit)
            ->withProperties(['unit' => $unit->unit_label, 'property_id' => $unit->property_id, ...$properties]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }
}
