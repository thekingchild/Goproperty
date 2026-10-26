<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Models\ImpersonationSession;

/**
 * A started support session and its one-time hand-off link (signed, valid
 * 60 seconds). The token in the link exists only here: the session row
 * keeps its hash.
 */
final readonly class HandOff
{
    public function __construct(
        public ImpersonationSession $session,
        public string $url,
    ) {}
}
