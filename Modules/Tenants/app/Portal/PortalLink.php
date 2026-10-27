<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\Invitation;
use SensitiveParameter;

/**
 * An invitation or sign-in link just made: the row (holding only the
 * token's SHA-256) and the link itself, which exists only here, in the
 * message that carries it, or on the agency's screen for copy / QR.
 */
final readonly class PortalLink
{
    public function __construct(
        public Invitation $invitation,
        #[SensitiveParameter] public string $url,
    ) {}
}
