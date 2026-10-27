<?php

declare(strict_types=1);

namespace Modules\Properties\Buildings;

/**
 * A building's amenities, `properties.amenities` (a json list of short
 * labels, DATABASE_SCHEMA.md § 3). The form offers the common ones as
 * checkboxes and takes others as free text; both end up as the same list.
 */
final class Amenities
{
    /** At most this many per building, each at most MAX_LENGTH characters. */
    public const int MAX_ITEMS = 30;

    public const int MAX_LENGTH = 40;

    /** Offered as checkboxes, in this order. */
    public const array SUGGESTED = [
        'Borehole',
        'Treated water',
        'Prepaid meter',
        'Generator',
        'Solar or inverter',
        'Security',
        'CCTV',
        'Gated compound',
        'Parking',
        'Elevator',
        'Swimming pool',
        'Gym',
        'Waste disposal',
        'Boys\' quarters',
    ];

    /**
     * Ticked labels and the free-text ones (comma or new-line separated),
     * trimmed, with single spaces, without duplicates (ignoring case; the
     * first spelling wins), suggested ones first.
     *
     * @param  array<mixed>  $ticked
     * @return list<string>
     */
    public static function normalize(array $ticked, string $other = ''): array
    {
        $labels = [];

        foreach ([...array_values($ticked), ...preg_split('/[,\n]+/', $other) ?: []] as $label) {
            if (! is_string($label)) {
                continue;
            }

            $label = trim((string) preg_replace('/\s+/u', ' ', $label));

            if ($label !== '' && ! array_key_exists(mb_strtolower($label), $labels)) {
                $labels[mb_strtolower($label)] = $label;
            }
        }

        $suggested = array_map(mb_strtolower(...), self::SUGGESTED);
        uksort($labels, static function (string $a, string $b) use ($suggested): int {
            $positionA = array_search($a, $suggested, true);
            $positionB = array_search($b, $suggested, true);

            return ($positionA === false ? PHP_INT_MAX : $positionA) <=> ($positionB === false ? PHP_INT_MAX : $positionB);
        });

        return array_values($labels);
    }

    /**
     * Splits a stored list back into the ticked suggestions and the rest.
     *
     * @param  list<string>  $amenities
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function split(array $amenities): array
    {
        $suggested = array_map(mb_strtolower(...), self::SUGGESTED);
        $ticked = [];
        $other = [];

        foreach ($amenities as $label) {
            $position = array_search(mb_strtolower($label), $suggested, true);

            if ($position === false) {
                $other[] = $label;
            } else {
                $ticked[] = self::SUGGESTED[$position];
            }
        }

        return [$ticked, $other];
    }
}
