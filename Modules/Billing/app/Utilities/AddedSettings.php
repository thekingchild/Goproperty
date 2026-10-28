<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use Modules\Billing\Models\UnitUtilitySetting;

/** What adding a utility to units did (UnitUtilitySettings::add): the settings made, and the units that had it already. */
final readonly class AddedSettings
{
    /**
     * @param  list<UnitUtilitySetting>  $created
     * @param  list<string>  $skipped  unit labels
     */
    public function __construct(
        public array $created,
        public array $skipped,
    ) {}
}
