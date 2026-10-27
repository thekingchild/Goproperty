<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who sent a proof of payment (`payment_proofs.submitted_by`, P03-09): the
 * tenant themselves (the portal, proven by their portal account linked to
 * the tenant record, P03-01b), or a member uploading what the tenant sent
 * them (a WhatsApp screenshot, a teller slip handed in).
 */
enum ProofSubmitter: string
{
    use HasValues;

    case Tenant = 'tenant';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'The tenant, in the portal',
            self::Member => 'A member, for the tenant',
        };
    }
}
