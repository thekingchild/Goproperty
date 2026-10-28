<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityType;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * The only writer of utility types (DATABASE_SCHEMA.md § 4
 * `utility_types`; P03-06a): add, edit, archive and restore, each checked
 * against UtilityTypePolicy for the acting member (so the suspension and
 * impersonation guards apply) and written to the audit log (log
 * `utilities`, the type as subject).
 *
 * Name and code are unique in the organization, archived types included
 * (restore one instead of adding it again). Once units use a type, its
 * charging mode and unit of measure stay as they are: readings already
 * taken count in that unit, and a flat utility has none. Archiving keeps
 * the units' settings and readings; the type is just no longer charged
 * (P03-06b) nor offered for new settings.
 */
final class UtilityTypes
{
    public const string LOG = 'utilities';

    private const array AUDITED = ['name', 'code', 'charging_mode', 'unit_of_measure', 'default_amount_kobo', 'default_cycle'];

    public function __construct(private readonly OrganizationContext $context) {}

    /**
     * @param  array<string, mixed>  $input  name, code, chargingMode, unitOfMeasure, amount (naira as typed), cycle
     *
     * @throws ValidationException
     */
    public function create(User $by, array $input): UtilityType
    {
        Gate::forUser($by)->authorize('create', UtilityType::class);

        $values = $this->validated($input, null);

        return DB::transaction(function () use ($by, $values): UtilityType {
            $type = new UtilityType;
            $type->forceFill($values)->save();
            $this->audit('utility_type.created', $type, $by, 'Utility type added');

            return $type;
        });
    }

    /**
     * @param  array<string, mixed>  $input  as for create()
     *
     * @throws ValidationException
     */
    public function update(User $by, UtilityType $type, array $input): UtilityType
    {
        Gate::forUser($by)->authorize('update', $type);

        $values = $this->validated($input, $type);

        return DB::transaction(function () use ($by, $type, $values): UtilityType {
            $type = UtilityType::query()->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
            $type->forceFill($values);
            $changed = array_values(array_intersect(self::AUDITED, array_keys($type->getDirty())));

            if ($changed !== []) {
                if (array_intersect($changed, ['charging_mode', 'unit_of_measure']) !== [] && $this->inUse($type)) {
                    throw ValidationException::withMessages(['chargingMode' => __('Units use this utility, so how it charges stays as it is. Add a new utility instead.')]);
                }

                $type->save();
                $this->audit('utility_type.updated', $type, $by, 'Utility type changed', ['changed' => $changed]);
            }

            return $type;
        });
    }

    public function archive(User $by, UtilityType $type): void
    {
        Gate::forUser($by)->authorize('delete', $type);

        DB::transaction(function () use ($by, $type): void {
            $type->forceFill(['active' => false])->save();
            $this->audit('utility_type.archived', $type, $by, 'Utility type archived');
        });
    }

    public function restore(User $by, UtilityType $type): void
    {
        Gate::forUser($by)->authorize('restore', $type);

        DB::transaction(function () use ($by, $type): void {
            $type->forceFill(['active' => true])->save();
            $this->audit('utility_type.restored', $type, $by, 'Utility type restored');
        });
    }

    /**
     * A utility type the Utilities import sheet names and the organization
     * does not have yet (P03-13b, DATA_IMPORT.md § 1 row 7: "utility types
     * created if missing"), inside the import's chunk transaction. Its code
     * is made from the name (unique, archived types included); it carries
     * the batch (`import_batch_id`), so a rollback archives it again.
     */
    public function addForImport(int $batchId, string $name, ChargingMode $mode, ?MeterUnit $unit, Money $amount, BillingCycle $cycle): UtilityType
    {
        $type = new UtilityType;
        $type->forceFill([
            'name' => $name,
            'code' => $this->freeCode($name),
            'charging_mode' => $mode,
            'unit_of_measure' => $mode === ChargingMode::Metered ? ($unit ?? MeterUnit::Unit) : null,
            'default_amount_kobo' => $amount,
            'default_cycle' => $cycle,
            'import_batch_id' => $batchId,
        ])->save();
        $this->audit('utility_type.imported', $type, null, 'Utility type added by an import', ['import_batch_id' => $batchId]);

        return $type;
    }

    /** A type an import added and its rollback archived, brought back by a later import (P03-13b). */
    public function restoreForImport(UtilityType $type, int $batchId): UtilityType
    {
        $type = UtilityType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
        $type->forceFill(['active' => true, 'import_batch_id' => $batchId])->save();
        $this->audit('utility_type.restored', $type, null, 'Utility type brought back by an import', ['import_batch_id' => $batchId]);

        return $type;
    }

    /**
     * An import's rollback (P03-13b): archives the types the batch added or
     * brought back. The rollback runs only while no unit outside the batch
     * uses them (Import\UtilitiesImportTarget::usedSince()).
     *
     * @return int how many were archived
     */
    public function rollBackImport(int $batchId): int
    {
        $archived = 0;

        foreach (UtilityType::query()->where('import_batch_id', $batchId)->where('active', true)->orderBy('id')->lockForUpdate()->get() as $type) {
            $type->forceFill(['active' => false])->save();
            $this->audit('utility_type.import_rolled_back', $type, null, 'Utility type archived with its import', ['import_batch_id' => $batchId]);
            $archived++;
        }

        return $archived;
    }

    /** A code for a new type from its name ("Security levy" → SECURITY-LEVY), unique in the organization, archived types included. */
    public function freeCode(string $name): string
    {
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper(Str::ascii($name))), '-');
        $base = $base === '' ? 'UTILITY' : mb_substr($base, 0, 16);
        $base = rtrim($base, '-');
        $code = $base;

        for ($n = 2; UtilityType::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'-'.$n;
        }

        return $code;
    }

    /** Whether any unit has a setting for this type (switched off ones too). */
    public function inUse(UtilityType $type): bool
    {
        return UnitUtilitySetting::query()->where('utility_type_id', $type->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> column => value
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?UtilityType $existing): array
    {
        $mode = ChargingMode::tryFrom(is_string($input['chargingMode'] ?? null) ? $input['chargingMode'] : '');
        $code = UtilityInput::text($input['code'] ?? null);

        $values = [
            'name' => UtilityInput::text($input['name'] ?? null),
            'code' => $code !== null ? strtoupper($code) : null,
            'chargingMode' => $mode?->value,
            'unitOfMeasure' => $mode === ChargingMode::Metered && is_string($input['unitOfMeasure'] ?? null) && $input['unitOfMeasure'] !== '' ? $input['unitOfMeasure'] : null,
            'amount' => is_string($input['amount'] ?? null) ? $input['amount'] : '',
            'cycle' => is_string($input['cycle'] ?? null) ? $input['cycle'] : null,
        ];
        $amount = UtilityInput::naira($values['amount']);
        $organizationId = $this->context->id();

        Validator::make($values, [
            'name' => ['required', 'string', 'min:2', 'max:80', 'not_regex:/[<>]/',
                Rule::unique('utility_types', 'name')->where('organization_id', $organizationId)->ignore($existing?->id)],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique('utility_types', 'code')->where('organization_id', $organizationId)->ignore($existing?->id)],
            'chargingMode' => ['required', Rule::in(ChargingMode::values())],
            'unitOfMeasure' => [Rule::requiredIf($mode === ChargingMode::Metered), 'nullable', Rule::in(MeterUnit::values())],
            'amount' => ['required', static function (string $attribute, mixed $value, \Closure $fail) use ($amount): void {
                if ($amount === null || ! $amount->isPositive()) {
                    $fail(__('Enter an amount in naira, such as 25,000.'));
                }
            }],
            'cycle' => ['required', Rule::in(BillingCycle::values())],
        ], [
            'name.unique' => __('A utility with this name exists already (archived ones count: restore it instead).'),
            'code.unique' => __('Another utility uses this code already (archived ones count).'),
            'code.regex' => __('Use capital letters, digits and dashes only, such as WATER or ELEC-1.'),
            'name.not_regex' => __('Use plain text only.'),
        ], [
            'chargingMode' => __('charging'),
            'unitOfMeasure' => __('unit of measure'),
            'cycle' => __('billing cycle'),
        ])->validate();

        assert($mode !== null && $amount !== null);

        return [
            'name' => $values['name'],
            'code' => $values['code'],
            'charging_mode' => $mode,
            'unit_of_measure' => $values['unitOfMeasure'] !== null ? MeterUnit::from($values['unitOfMeasure']) : null,
            'default_amount_kobo' => $amount,
            'default_cycle' => BillingCycle::from((string) $values['cycle']),
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, UtilityType $type, ?User $by, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($type)
            ->withProperties(['utility' => $type->name, ...$properties]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }
}
