<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Leasing\Contracts\BillingCycle;
use Throwable;

/**
 * Server-side checks for a lease's terms (DATABASE_SCHEMA.md § 3
 * `leases`), for every LeaseService writer: the input keys are the lease
 * form's (billingCycle, startDate and endDate as Y-m-d, rent and deposit
 * in naira as typed, dueDayOffset, paidUntil, autoRenew, notes). Amounts
 * become App\Support\Money (kobo) here and nowhere else.
 *
 * `rent` is what one period of the cycle costs (a yearly lease: the rent
 * for a year; monthly: for a month). The due-day offset stays inside the
 * shortest period of the cycle (a daily lease is due on its day; weekly
 * within 6 days; month-based within 27), so a bill never falls due after
 * its period ends (P03-02 follow-up). "Paid until" is the last day the
 * tenant has already paid for (on or after the start, by the end): billing
 * starts with the first period after it.
 */
final class LeaseTerms
{
    /** ₦10bn a period: far above any rent, well inside the kobo column. */
    public const int MAX_NAIRA = 10_000_000_000;

    public const int MAX_NOTES = 2000;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function validated(array $input, ?CarbonImmutable $startsAfter = null): Terms
    {
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $values = [
            'billingCycle' => $text($input['billingCycle'] ?? null),
            'startDate' => $text($input['startDate'] ?? null),
            'endDate' => $text($input['endDate'] ?? null),
            'rent' => $text($input['rent'] ?? null),
            'deposit' => $text($input['deposit'] ?? null),
            'dueDayOffset' => is_int($input['dueDayOffset'] ?? null) ? (string) $input['dueDayOffset'] : $text($input['dueDayOffset'] ?? null),
            'paidUntil' => $text($input['paidUntil'] ?? null),
            'autoRenew' => (bool) ($input['autoRenew'] ?? false),
            'notes' => $text($input['notes'] ?? null),
        ];

        $cycle = BillingCycle::tryFrom((string) $values['billingCycle']);
        $start = LeaseDates::parse($values['startDate']);
        $end = LeaseDates::parse($values['endDate']);

        Validator::make($values, [
            'billingCycle' => ['required', Rule::in(BillingCycle::values())],
            'startDate' => ['required', self::dateRule(), self::startRule($startsAfter)],
            'endDate' => ['required', self::dateRule(), static function (string $attribute, mixed $value, Closure $fail) use ($start, $end): void {
                if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
                    $fail(__('The lease must end after it starts.'));
                }
            }],
            'rent' => ['required', self::amountRule(positive: true)],
            'deposit' => ['nullable', self::amountRule(positive: false)],
            'dueDayOffset' => ['nullable', 'integer', 'min:0', 'max:'.self::maxDueDayOffset($cycle)],
            'paidUntil' => ['nullable', self::dateRule(), static function (string $attribute, mixed $value, Closure $fail) use ($start, $end): void {
                $paid = LeaseDates::parse(is_string($value) ? $value : null);

                if ($paid !== null && $start !== null && $end !== null && ($paid->lessThan($start) || $paid->greaterThan($end))) {
                    $fail(__('Paid until must be a day between the start and the end of the lease.'));
                }
            }],
            'autoRenew' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES],
        ], [
            'dueDayOffset.max' => $cycle === BillingCycle::Daily
                ? __('A daily lease is due on its day: the offset must be 0.')
                : __('The bill must fall due within the period: at most :max days after it starts.'),
        ], [
            'billingCycle' => 'billing cycle',
            'startDate' => 'start date',
            'endDate' => 'end date',
            'dueDayOffset' => 'days after the period start',
            'paidUntil' => 'paid until',
        ])->validate();

        assert($cycle instanceof BillingCycle && $start instanceof CarbonImmutable && $end instanceof CarbonImmutable);

        return new Terms(
            cycle: $cycle,
            start: $start,
            end: $end,
            rent: self::naira((string) $values['rent']) ?? Money::zero(),
            deposit: $values['deposit'] === null ? Money::zero() : (self::naira($values['deposit']) ?? Money::zero()),
            dueDayOffset: $values['dueDayOffset'] === null ? 0 : (int) $values['dueDayOffset'],
            paidUntil: LeaseDates::parse($values['paidUntil']),
            autoRenew: $values['autoRenew'],
            notes: $values['notes'],
        );
    }

    /**
     * The tenant and unit chosen on the form (a new lease only).
     *
     * @param  array<string, mixed>  $input
     * @return array{tenantId: int, unitId: int}
     *
     * @throws ValidationException
     */
    public function parties(array $input): array
    {
        $values = ['tenantId' => $input['tenantId'] ?? null, 'unitId' => $input['unitId'] ?? null];

        Validator::make($values, [
            'tenantId' => ['required', 'integer', 'min:1'],
            'unitId' => ['required', 'integer', 'min:1'],
        ], [
            'tenantId.required' => __('Choose the tenant.'),
            'unitId.required' => __('Choose the unit.'),
        ], ['tenantId' => 'tenant', 'unitId' => 'unit'])->validate();

        return ['tenantId' => (int) $values['tenantId'], 'unitId' => (int) $values['unitId']];
    }

    /** The largest due-day offset the cycle allows (inside its shortest period). */
    public static function maxDueDayOffset(?BillingCycle $cycle): int
    {
        return match ($cycle) {
            BillingCycle::Daily => 0,
            BillingCycle::Weekly => 6,
            default => 27,
        };
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

    private static function dateRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (LeaseDates::parse(is_string($value) ? $value : null) === null) {
                $fail(__('Enter a date.'));
            }
        };
    }

    private static function startRule(?CarbonImmutable $startsAfter): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($startsAfter): void {
            $start = LeaseDates::parse(is_string($value) ? $value : null);

            if ($startsAfter !== null && $start !== null && $start->lessThanOrEqualTo($startsAfter)) {
                $fail(__('A renewal starts after the lease it renews ends (:date).', ['date' => $startsAfter->format('d/m/Y')]));
            }
        };
    }

    private static function amountRule(bool $positive): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($positive): void {
            $amount = is_string($value) ? self::naira($value) : null;

            if ($amount === null) {
                $fail(__('Enter an amount in naira, such as 1,500,000.'));
            } elseif ($positive && ! $amount->isPositive()) {
                $fail(__('Enter an amount above ₦0.'));
            } elseif ($amount->greaterThan(Money::naira(self::MAX_NAIRA))) {
                $fail(__('Enter an amount below ₦10,000,000,000.'));
            }
        };
    }
}
