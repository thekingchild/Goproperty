<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Currency;
use DivisionByZeroError;
use InvalidArgumentException;
use JsonSerializable;
use OverflowException;
use RoundingMode;
use Stringable;

/**
 * An amount of naira as integer kobo (AGENT.md § 3 rule 2). All money maths
 * goes through here: no floats, no `/ 100` elsewhere. Immutable; every
 * operation returns a new value and fails loudly on overflow instead of
 * silently turning into a float.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $kobo)
    {
        if ($kobo === PHP_INT_MIN) {
            throw new OverflowException('The amount is out of range.');
        }
    }

    public static function kobo(int $kobo): self
    {
        return new self($kobo);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Whole naira (int) or an exact naira string: "1250000", "1,250,000.50",
     * "₦1,250,000", "-500.5". Commas must group thousands correctly; at most
     * two decimals, so no rounding happens.
     */
    public static function naira(int|string $naira): self
    {
        if (is_int($naira)) {
            return new self(self::multiply($naira, 100));
        }

        if (preg_match('/^(-)?\s*₦?\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/uD', trim($naira), $match) !== 1) {
            throw new InvalidArgumentException('"'.$naira.'" is not a naira amount such as 1,250,000.50.');
        }

        $whole = ltrim(str_replace(',', '', $match[2]), '0');

        if (strlen($whole) > 16) {
            throw new OverflowException('The amount is out of range.');
        }

        $kobo = self::add(self::multiply((int) $whole, 100), (int) str_pad($match[3] ?? '', 2, '0'));

        return new self($match[1] === '-' ? -$kobo : $kobo);
    }

    /** @param  iterable<self>  $amounts */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount->kobo);
        }

        return new self($total);
    }

    public static function min(self $first, self ...$others): self
    {
        return array_reduce($others, fn (self $carry, self $amount): self => $amount->kobo < $carry->kobo ? $amount : $carry, $first);
    }

    public static function max(self $first, self ...$others): self
    {
        return array_reduce($others, fn (self $carry, self $amount): self => $amount->kobo > $carry->kobo ? $amount : $carry, $first);
    }

    public function currency(): Currency
    {
        return Currency::NGN;
    }

    public function plus(self $other): self
    {
        return new self(self::add($this->kobo, $other->kobo));
    }

    public function minus(self $other): self
    {
        return new self(self::add($this->kobo, -$other->kobo));
    }

    public function times(int $factor): self
    {
        return new self(self::multiply($this->kobo, $factor));
    }

    /**
     * A share in basis points (10000 = 100%, 750 = 7.5%), rounded to the
     * nearest kobo, halves away from zero unless told otherwise.
     */
    public function percentage(int $basisPoints, RoundingMode $mode = RoundingMode::HalfAwayFromZero): self
    {
        return new self(self::divide(self::multiply($this->kobo, $basisPoints), 10000, $mode));
    }

    /** Divides by a whole number, rounded to the nearest kobo. */
    public function dividedBy(int $divisor, RoundingMode $mode = RoundingMode::HalfAwayFromZero): self
    {
        return new self(self::divide($this->kobo, $divisor, $mode));
    }

    /**
     * Splits the amount by ratios without losing or creating a kobo: the
     * leftover kobo go to the parts with the largest remainders, earlier
     * parts first on a tie. The parts always add up to this amount.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ($ratios === [] || min($ratios) < 0 || array_sum($ratios) <= 0) {
            throw new InvalidArgumentException('Ratios must be zero or more, with at least one above zero.');
        }

        $total = abs($this->kobo);
        $sum = array_sum($ratios);
        $parts = [];
        $remainders = [];

        foreach ($ratios as $index => $ratio) {
            $share = self::multiply($total, $ratio);
            $parts[$index] = intdiv($share, $sum);
            $remainders[$index] = $share % $sum;
        }

        $left = $total - array_sum($parts);
        arsort($remainders, SORT_NUMERIC);

        foreach (array_keys($remainders) as $index) {
            if ($left === 0) {
                break;
            }

            $parts[$index]++;
            $left--;
        }

        ksort($parts);

        return array_values(array_map(fn (int $kobo): self => new self($this->kobo < 0 ? -$kobo : $kobo), $parts));
    }

    /**
     * Equal parts; any leftover kobo go to the first parts.
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Split into one part or more.');
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    public function negate(): self
    {
        return new self(-$this->kobo);
    }

    public function abs(): self
    {
        return new self(abs($this->kobo));
    }

    public function isZero(): bool
    {
        return $this->kobo === 0;
    }

    public function isPositive(): bool
    {
        return $this->kobo > 0;
    }

    public function isNegative(): bool
    {
        return $this->kobo < 0;
    }

    public function equals(self $other): bool
    {
        return $this->kobo === $other->kobo;
    }

    /** -1, 0 or 1. */
    public function compareTo(self $other): int
    {
        return $this->kobo <=> $other->kobo;
    }

    public function greaterThan(self $other): bool
    {
        return $this->kobo > $other->kobo;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->kobo >= $other->kobo;
    }

    public function lessThan(self $other): bool
    {
        return $this->kobo < $other->kobo;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->kobo <= $other->kobo;
    }

    /** "1250000.50": always two decimals, no symbol or separators (exports, Paystack). */
    public function toDecimalString(): string
    {
        $absolute = abs($this->kobo);

        return ($this->kobo < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * For people (UX_DESIGN.md § 1): "₦1,250,000", kobo shown only when not
     * zero ("₦1,250,000.50"), minus sign before the symbol ("-₦500").
     */
    public function format(): string
    {
        $absolute = abs($this->kobo);
        $whole = (string) intdiv($absolute, 100);
        $grouped = ltrim(strrev(implode(',', str_split(strrev($whole), 3))), ',');
        $kobo = $absolute % 100;

        return ($this->kobo < 0 ? '-' : '').$this->currency()->symbol().$grouped
            .($kobo !== 0 ? '.'.str_pad((string) $kobo, 2, '0', STR_PAD_LEFT) : '');
    }

    /** "Two million, nine hundred thousand naira only" (receipts; AmountInWords). */
    public function inWords(): string
    {
        return AmountInWords::of($this);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** Serialised as integer kobo, the same as the database. */
    public function jsonSerialize(): int
    {
        return $this->kobo;
    }

    /** PHP turns an overflowing integer into a float, so check the bounds first. */
    private static function add(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN + 1 - $b)) {
            throw new OverflowException('The amount is out of range.');
        }

        return $a + $b;
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        if ($a === PHP_INT_MIN || $b === PHP_INT_MIN || abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new OverflowException('The amount is out of range.');
        }

        return $a * $b;
    }

    /** Exact integer division with the given rounding. */
    private static function divide(int $numerator, int $denominator, RoundingMode $mode): int
    {
        if ($denominator === 0) {
            throw new DivisionByZeroError('Division by zero.');
        }

        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = abs($numerator % $denominator);

        if ($remainder === 0) {
            return $quotient;
        }

        $sign = $numerator < 0 ? -1 : 1;
        // Compare the remainder with half the divisor without overflowing.
        $half = $remainder <=> ($denominator - $remainder);

        $awayFromZero = match ($mode) {
            RoundingMode::TowardsZero => false,
            RoundingMode::AwayFromZero => true,
            RoundingMode::PositiveInfinity => $sign > 0,
            RoundingMode::NegativeInfinity => $sign < 0,
            RoundingMode::HalfAwayFromZero => $half >= 0,
            RoundingMode::HalfTowardsZero => $half > 0,
            RoundingMode::HalfEven => $half > 0 || ($half === 0 && $quotient % 2 !== 0),
            RoundingMode::HalfOdd => $half > 0 || ($half === 0 && $quotient % 2 === 0),
        };

        return $awayFromZero ? $quotient + $sign : $quotient;
    }
}
