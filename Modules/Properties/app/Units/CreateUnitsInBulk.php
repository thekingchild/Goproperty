<?php

declare(strict_types=1);

namespace Modules\Properties\Units;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * Adds several units with the same details to one building at once
 * (IMPLEMENTATION_PLAN.md P02-02, "10 identical flats"): labels from a
 * pattern with `{n}` and a start number ("Flat {n}" from 1 → Flat 1 … Flat
 * 10; "A{n}" from 101 → A101 …). Checked against UnitPolicy::create like a
 * single unit.
 *
 * All or nothing, in one transaction: the building row is locked, every
 * label is checked first, and a label the building already has stops the
 * whole add with an error naming each such label; if another request adds
 * one of the labels meanwhile, the unique index stops it and everything
 * added so far is rolled back. A label of an archived unit brings that
 * unit back with these details (P02 decision 3), as Units::create does.
 */
final readonly class CreateUnitsInBulk
{
    public const string PLACEHOLDER = '{n}';

    public const int MAX_UNITS = 100;

    public const int MAX_START = 99_999;

    public function __construct(private Units $units, private UnitDetails $details) {}

    /**
     * @param  array<string, mixed>  $input  pattern, start, count, plus UnitDetails' keys
     * @return list<Unit> in label order
     *
     * @throws ValidationException
     */
    public function handle(User $by, Property $property, array $input): array
    {
        Gate::forUser($by)->authorize('create', [Unit::class, $property]);

        $pattern = UnitDetails::label($input['pattern'] ?? null);
        $start = $input['start'] ?? null;
        $count = $input['count'] ?? null;

        $values = $this->details->validated($input, null, [
            'pattern' => $pattern,
            'start' => is_string($start) ? trim($start) : $start,
            'count' => is_string($count) ? trim($count) : $count,
        ], [
            'pattern' => ['required', 'string', 'max:'.(UnitDetails::MAX_LABEL - 5), 'not_regex:/[<>]/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || substr_count($value, self::PLACEHOLDER) !== 1) {
                    $fail(__('Put {n} once in the pattern where the number goes, such as “Flat {n}”.'));
                } elseif (preg_match('/[{}]/', str_replace(self::PLACEHOLDER, '', $value)) === 1) {
                    $fail(__('Use braces only for {n}.'));
                }
            }],
            'start' => ['required', 'integer', 'between:0,'.self::MAX_START],
            'count' => ['required', 'integer', 'between:1,'.self::MAX_UNITS],
        ], [
            'pattern' => 'label pattern',
            'start' => 'first number',
            'count' => 'number of units',
        ]);

        $labels = self::labels((string) $pattern, (int) $start, (int) $count);
        $current = null;

        try {
            return DB::transaction(function () use ($by, $property, $labels, $values, &$current): array {
                $building = $this->units->lockBuilding($property);
                $existing = $this->units->existing($building, $labels);

                $taken = array_values(array_filter($labels, static fn (string $label): bool => isset($existing[mb_strtolower($label)]) && ! $existing[mb_strtolower($label)]->trashed()));

                if ($taken !== []) {
                    throw self::takenError($taken, $labels, $building);
                }

                $added = [];

                foreach ($labels as $label) {
                    $current = $label;
                    $added[] = $this->units->place($by, $building, $label, $values, $existing[mb_strtolower($label)] ?? null, ['bulk' => count($labels)]);
                }

                return $added;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request added one of these labels after the check:
            // the transaction is rolled back, so no unit of this add exists.
            throw self::takenError([(string) $current], $labels, $property);
        }
    }

    /**
     * The labels a pattern gives, in order.
     *
     * @return list<string>
     */
    public static function labels(string $pattern, int $start, int $count): array
    {
        $labels = [];

        for ($n = $start; $n < $start + $count; $n++) {
            $labels[] = str_replace(self::PLACEHOLDER, (string) $n, $pattern);
        }

        return $labels;
    }

    /**
     * One error naming every label already in the building (key `labels`),
     * and one per label (`labels.<position>`), so the form can show each.
     *
     * @param  list<string>  $taken
     * @param  list<string>  $labels
     */
    private static function takenError(array $taken, array $labels, Property $building): ValidationException
    {
        $named = count($taken) > 5
            ? implode(', ', array_slice($taken, 0, 5)).' '.__('and :count more', ['count' => count($taken) - 5])
            : implode(', ', $taken);

        $messages = ['labels' => [trans_choice(
            ':labels already exists in :building. Change the first number or the pattern: no unit was added.|:labels already exist in :building. Change the first number or the pattern: no unit was added.',
            count($taken),
            ['labels' => $named, 'building' => $building->name],
        )]];

        foreach ($taken as $label) {
            $position = array_search($label, $labels, true);
            $messages['labels.'.(is_int($position) ? $position : 0)] = [Units::takenMessage($label, $building)];
        }

        return ValidationException::withMessages($messages);
    }
}
