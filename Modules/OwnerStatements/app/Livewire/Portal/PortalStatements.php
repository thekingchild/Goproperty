<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Livewire\Portal;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\PortalAgency;
use Modules\OwnerStatements\Statements\StatementMonth;
use Modules\Properties\Contracts\OwnerPortalAccess;
use Modules\Properties\Contracts\PortalOwner;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;

/**
 * The owner portal's statements, /owner/statements (P08-05): for every
 * agency that manages buildings of the signed-in owner, in its current
 * colours and logo, the leases ending in the next 90 days with their
 * renewal state (the tenant's name; phone and email only when the agency
 * shares them with this owner) and the last 12 months: the net due to the
 * owner and whether the agency marked the month settled. Read through
 * Properties' OwnerPortalAccess (the "linked to my user" path). Read-only:
 * no actions (ReadOnlyOwnerPage).
 */
#[Layout('layouts::app')]
#[Title('Statements')]
final class PortalStatements extends Component implements ReadOnlyOwnerPage
{
    public function render(OwnerPortalAccess $portal, OwnerStatements $statements, OrganizationContext $context): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $agencies = [];

        foreach ($portal->owners($user) as $owner) {
            $agencies[] = $portal->within($user, $owner->ownerProfileId, static fn (PortalOwner $owner): array => [
                'owner' => $owner,
                'agency' => PortalAgency::of($context->get()),
                'terms' => $statements->terms($owner->ownerProfileId),
                'renewals' => $statements->renewals($owner->ownerProfileId),
                'months' => $statements->months($owner->ownerProfileId, StatementMonth::current()->lastMonths()),
            ]);
        }

        return view('ownerstatements::livewire.portal.statements', ['agencies' => array_values(array_filter($agencies))]);
    }
}
