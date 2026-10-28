<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A meter reading or a consumption (`utility_readings.previous_value`,
 * `current_value`, `consumption`; `unit_utility_settings.starting_reading`):
 * an exact decimal with at most three places, kept as whole thousandths so
 * comparing and subtracting never go through floats (AGENT.md § 3 rule 2
 * applies to money; meter values follow it too, since P03-06b multiplies
 * them by a rate in kobo).
 */
final readonly class MeterValue implements JsonSerializable, Stringable
{
    public const int SCALE = 1000;

    /** The largest value a `decimal(15,3)` column holds. */
    public const int MAX_THOUSANDTHS = 999_999_999_999_999;

    private function __construct(public int $thousandths)
    {
        if ($thousandths < 0 || $thousandths > self::MAX_THOUSANDTHS) {
            throw new InvalidArgumentException('A meter value is between 0 and 999,999,999,999.999.');
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofThousandths(int $thousandths): self
    {
        return new self($thousandths);
    }

    /**
     * "12345", "12,345.6", "0.125" (as typed or as the database returns a
     * decimal). Null for anything else: negative, more than three decimals,
     * letters, too large.
     */
    public static function parse(?string $typed): ?self
    {
        $typed = str_replace([' ', "\u{00A0}"], '', trim((string) $typed));

        if (preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,3}))?$/D', $typed, $match) !== 1) {
            return null;
        }

        $whole = ltrim(str_replace(',', '', $match[1]), '0');

        if (strlen($whole) > 12) {
            return null;
        }

        return new self((int) $whole * self::SCALE + (int) str_pad($match[2] ?? '', 3, '0'));
    }

    /** As the database stores it: "12345.600". */
    public function toDecimalString(): string
    {
        return intdiv($this->thousandths, self::SCALE).'.'.str_pad((string) ($this->thousandths % self::SCALE), 3, '0', STR_PAD_LEFT);
    }

    /** For people: "12,345.6", "0.125", "3" (no trailing zeros). */
    public function format(): string
    {
        $whole = number_format(intdiv($this->thousandths, self::SCALE));
        $fraction = rtrim(str_pad((string) ($this->thousandths % self::SCALE), 3, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    /** For a form field: "12345.6" (no thousands separators). */
    public function input(): string
    {
        return str_replace(',', '', $this->format());
    }

    public function compareTo(self $other): int
    {
        return $this->thousandths <=> $other->thousandths;
    }

    public function lessThan(self $other): bool
    {
        return $this->thousandths < $other->thousandths;
    }

    public function equals(self $other): bool
    {
        return $this->thousandths === $other->thousandths;
    }

    /** This minus an earlier, smaller or equal value (a consumption). */
    public function minus(self $earlier): self
    {
        if ($earlier->thousandths > $this->thousandths) {
            throw new InvalidArgumentException('A meter does not go backwards.');
        }

        return new self($this->thousandths - $earlier->thousandths);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }
}
