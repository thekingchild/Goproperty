<?php

declare(strict_types=1);

namespace Modules\Properties\Units;

use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\Unit;
use Throwable;

/**
 * Server-side checks for a unit's details (DATABASE_SCHEMA.md § 3 `units`),
 * shared by Units (one unit) and CreateUnitsInBulk (the same details for
 * every unit). Input keys are the form's: unitType, bedrooms, bathrooms,
 * sizeSqm, floor, baseRent and serviceCharge (naira as typed, such as
 * "1,500,000"), status, description. Amounts become App\Support\Money
 * (kobo) here and nowhere else.
 */
final class UnitDetails
{
    public const int MAX_LABEL = 60;

    /** ₦10bn a year: far above any rent, well inside the kobo column. */
    public const int MAX_NAIRA = 10_000_000_000;

    /** "  Flat   3B " → "Flat 3B"; empty → null. */
    public static function label(mixed $value): ?string
    {
        $value = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

        return $value === '' ? null : $value;
    }

    /**
     * The rules for one label.
     *
     * @return list<string>
     */
    public static function labelRules(): array
    {
        return ['required', 'string', 'max:'.self::MAX_LABEL, 'not_regex:/[<>{}]/'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $extraValues  more values to check with the details (such as the label)
     * @param  array<string, mixed>  $extraRules
     * @param  array<string, string>  $extraAttributes
     * @return array<string, mixed> column => value (no label, no building)
     *
     * @throws ValidationException
     */
    public function validated(array $input, ?Unit $existing, array $extraValues = [], array $extraRules = [], array $extraAttributes = []): array
    {
        $text = static function (mixed $value): ?string {
            $value = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

            return $value === '' ? null : $value;
        };
        $number = static fn (mixed $value): mixed => $value === null || (is_string($value) && trim($value) === '') ? null : (is_string($value) ? trim($value) : $value);

        // A lease holds an occupied or reserved unit: its status is Leasing's
        // to change (UnitStatus), so the form's choice is ignored.
        $heldByLease = $existing !== null && $existing->status->heldByLease();

        $values = $extraValues + [
            'unitType' => $text($input['unitType'] ?? null),
            'bedrooms' => $number($input['bedrooms'] ?? null),
            'bathrooms' => $number($input['bathrooms'] ?? null),
            'sizeSqm' => $number($input['sizeSqm'] ?? null),
            'floor' => $text($input['floor'] ?? null),
            'baseRent' => $text($input['baseRent'] ?? null),
            'serviceCharge' => $text($input['serviceCharge'] ?? null),
            'status' => $heldByLease ? null : ($text($input['status'] ?? null) ?? UnitStatus::Vacant->value),
            'description' => is_string($input['description'] ?? null) && trim($input['description']) !== '' ? trim($input['description']) : null,
        ];

        $manualStatuses = array_map(static fn (UnitStatus $status): string => $status->value, UnitStatus::manual());

        Validator::make($values, $extraRules + [
            'unitType' => ['required', Rule::in(UnitType::values())],
            'bedrooms' => ['nullable', 'integer', 'between:0,20'],
            'bathrooms' => ['nullable', 'integer', 'between:0,20'],
            'sizeSqm' => ['nullable', 'integer', 'between:1,100000'],
            'floor' => ['nullable', 'string', 'max:20', 'not_regex:/[<>]/'],
            'baseRent' => ['required', self::amountRule()],
            'serviceCharge' => ['nullable', self::amountRule()],
            'status' => $heldByLease ? ['prohibited'] : ['required', Rule::in($manualStatuses)],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            '*.not_regex' => __('Use plain text only.'),
            'status.in' => __('Choose vacant, under maintenance or unavailable. Occupied and reserved follow the unit\'s lease.'),
        ], $extraAttributes + [
            'unitType' => 'unit type',
            'sizeSqm' => 'size',
            'baseRent' => 'rent',
            'serviceCharge' => 'service charge',
        ])->validate();

        $columns = [
            'unit_type' => UnitType::from((string) $values['unitType']),
            'bedrooms' => $values['bedrooms'] === null ? null : (int) $values['bedrooms'],
            'bathrooms' => $values['bathrooms'] === null ? null : (int) $values['bathrooms'],
            'size_sqm' => $values['sizeSqm'] === null ? null : (int) $values['sizeSqm'],
            'floor' => $values['floor'],
            'base_rent_kobo' => self::naira((string) $values['baseRent']) ?? Money::zero(),
            'service_charge_kobo' => $values['serviceCharge'] === null ? Money::zero() : (self::naira((string) $values['serviceCharge']) ?? Money::zero()),
            'description' => $values['description'],
        ];

        if (! $heldByLease) {
            $columns['status'] = UnitStatus::from((string) $values['status']);
        }

        return $columns;
    }

    /** An amount of naira as typed ("1,500,000", "₦250,000.50"), or null when it is not one. */
    public static function naira(string $typed): ?Money
    {
        $typed = str_replace([' ', "\u{00A0}"], '', trim($typed));

        try {
            $amount = Money::naira($typed);
        } catch (Throwable) {
            return null;
        }

        return $amount->isNegative() ? null : $amount;
    }

    /** What the form shows for an amount: "1,500,000" or "1,500,000.50"; empty for none when asked. */
    public static function nairaInput(Money $amount, bool $emptyWhenZero = false): string
    {
        if ($emptyWhenZero && $amount->isZero()) {
            return '';
        }

        return str_replace($amount->currency()->symbol(), '', $amount->format());
    }

    private static function amountRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $amount = is_string($value) ? self::naira($value) : null;

            if ($amount === null) {
                $fail(__('Enter an amount in naira, such as 1,500,000.'));
            } elseif ($amount->greaterThan(Money::naira(self::MAX_NAIRA))) {
                $fail(__('Enter an amount below ₦10,000,000,000.'));
            }
        };
    }
}
