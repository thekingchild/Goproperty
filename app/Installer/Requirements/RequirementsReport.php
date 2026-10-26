<?php

declare(strict_types=1);

namespace App\Installer\Requirements;

final readonly class RequirementsReport
{
    /**
     * @param  list<RequirementCheck>  $checks
     */
    public function __construct(public array $checks) {}

    /** True when no blocking check failed. */
    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->blocks()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<RequirementCheck> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (RequirementCheck $check): bool => $check->blocks()));
    }

    /**
     * For the locked first screen (INSTALLER.md § 4): pass or fail per group
     * of blocking checks, with no paths, values or versions.
     *
     * @return array<string, bool>
     */
    public function publicSummary(): array
    {
        $summary = [];

        foreach ($this->checks as $check) {
            if ($check->blocking) {
                $summary[$check->group] = ($summary[$check->group] ?? true) && ! $check->blocks();
            }
        }

        return $summary;
    }
}
