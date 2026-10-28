<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\Invitation;
use SensitiveParameter;

/**
 * An owner invitation just made (P03-15): the row (holding only the
 * token's SHA-256) and the link itself, which exists only here, in the
 * message that carries it, or on the agency's screen for copy / QR.
 */
final readonly class OwnerPortalLink
{
    public function __construct(
        public Invitation $invitation,
        #[SensitiveParameter] public string $url,
    ) {}
}
