<?php

declare(strict_types=1);

namespace App\Compliance;

/**
 * Submitting an application that lacks a required document (e.g. Tier 2
 * without the SCUML certificate) or detail. `problems` lists what is
 * missing, one sentence each.
 */
final class ApplicationIncomplete extends TierApplicationRefused
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The application is not complete yet: '.implode(' ', $problems));
    }
}
