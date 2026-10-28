<?php

declare(strict_types=1);

namespace Modules\Billing\Handover;

use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Str;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\UtilityTypes;

/**
 * The Billing module's part of a management handover (BILLING_PAYMENTS.md
 * § 7.4, DATABASE_SCHEMA.md § 4; App\Tenancy\Moves): the moved units'
 * utility settings, with where their billing stands (`next_billing_date`,
 * the billing hold), and every meter reading of them (removed ones too:
 * the meter's history), with their meter photos (App\Media\MediaMover).
 *
 * Utility types are organization-wide configuration and stay: each moved
 * setting points to the receiving organization's type with the same code,
 * else the same name, else a copy of the old type made there. An import
 * reference already used there is cleared on the moved setting; the import
 * batch is cleared.
 *
 * Bills never move (history of the organization that issued them). A
 * billed reading keeps its `bill_id`, a link into that history: it is what
 * says the reading was billed, so the receiving organization never bills
 * the same consumption again.
 */
final readonly class UtilitiesMover implements MovesBuildingRecords
{
    public function __construct(private OrganizationContext $context) {}

    public function order(): int
    {
        return 40;
    }

    public function move(BuildingMove $move): void
    {
        $settings = UnitUtilitySetting::query()
            ->whereIn('property_id', $move->propertyIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($settings->isEmpty()) {
            return;
        }

        $types = UtilityType::query()->whereKey($settings->pluck('utility_type_id')->unique()->all())->get()->keyBy('id');
        $typeMap = $this->context->run($move->to, fn (): array => $types->map(fn (UtilityType $type): int => $this->typeThere($move, $type))->all());

        $refs = $settings->pluck('external_ref')->filter()->values()->all();
        $taken = $refs === [] ? [] : $this->context->run($move->to, static fn (): array => UnitUtilitySetting::query()->whereIn('external_ref', $refs)->pluck('external_ref')->all());

        foreach ($settings as $setting) {
            $refTaken = $setting->external_ref !== null && in_array($setting->external_ref, $taken, true);
            $move->reassign(UnitUtilitySetting::class, [$setting->id], [
                'utility_type_id' => $typeMap[$setting->utility_type_id],
                'external_ref' => $refTaken ? null : $setting->external_ref,
                'import_batch_id' => null,
            ]);
            $move->count('external_refs_cleared', $refTaken ? 1 : 0);
        }

        $readings = UtilityReading::query()->withTrashed()
            ->whereIn('unit_utility_setting_id', $settings->modelKeys())
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $move->reassign(UtilityReading::class, $readings, withTrashed: true);
    }

    /** The receiving organization's type of the same code, else name; else a copy of $type made there. */
    private function typeThere(BuildingMove $move, UtilityType $type): int
    {
        $match = UtilityType::query()->where('code', $type->code)->first()
            ?? UtilityType::query()->where('name', $type->name)->first();

        if ($match !== null) {
            return $match->id;
        }

        $copy = new UtilityType;
        $copy->forceFill([
            'name' => $type->name,
            'code' => Str::limit($type->code, 20, ''),
            'charging_mode' => $type->charging_mode,
            'unit_of_measure' => $type->unit_of_measure,
            'default_amount_kobo' => $type->default_amount_kobo,
            'default_cycle' => $type->default_cycle,
            'active' => $type->active,
        ])->save();

        activity(UtilityTypes::LOG)
            ->event('utility_type.brought_over')
            ->performedOn($copy)
            ->withProperties([
                'handover_id' => $move->handoverId,
                'from_organization_id' => $move->from->id,
                'from_utility_type_id' => $type->id,
                'direction' => $move->direction->value,
            ])
            ->log("Utility type added with buildings brought over from {$move->from->name}");

        $move->count('utility_types_created');

        return $copy->id;
    }
}
