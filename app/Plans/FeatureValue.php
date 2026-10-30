<?php

declare(strict_types=1);

namespace App\Plans;

/**
 * One feature's value for a plan version or an organization: a switch
 * (`enabled`) or a limit (`limit`, null = unlimited; a limit is always
 * "enabled").
 */
final readonly class FeatureValue
{
    private function __construct(public bool $enabled, public ?int $limit) {}

    public static function on(): self
    {
        return new self(true, null);
    }

    public static function off(): self
    {
        return new self(false, null);
    }

    public static function limit(?int $limit): self
    {
        return new self(true, $limit === null ? null : max(0, $limit));
    }

    public static function of(Feature $feature, bool $enabled, ?int $limit): self
    {
        return $feature->isLimit() ? self::limit($limit) : ($enabled ? self::on() : self::off());
    }

    /** "Unlimited", "10", "✓" or "—" */
    public function describe(Feature $feature): string
    {
        if ($feature->isLimit()) {
            return $this->limit === null ? 'Unlimited' : number_format($this->limit);
        }

        return $this->enabled ? 'Included' : 'Not included';
    }

    /** Does a count of $used leave room for one more? */
    public function allowsAnother(int $used): bool
    {
        return $this->limit === null || $used < $this->limit;
    }

    public function equals(self $other): bool
    {
        return $this->enabled === $other->enabled && $this->limit === $other->limit;
    }
}
