<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Livewire\Portal;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\PortalAgency;
use Modules\OwnerStatements\Statements\StatementMonth;
use Modules\Properties\Contracts\OwnerPortalAccess;
use Modules\Properties\Contracts\PortalOwner;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;

/**
 * One month of the owner's statement with one agency,
 * /owner/statements/{owner}/{YYYY-MM} (P08-05): per building, rent and
 * utilities collected (per unit and each amount), the bills issued and
 * their status, the expenses charged, the commission and the net due; the
 * agency's Settled / Unsettled mark with the date paid and the reference;
 * the PDF. Found among the signed-in owner's own profiles on every render
 * (OwnerPortalAccess::within), so anyone else's answers 404. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Statement')]
final class PortalStatementMonth extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $ownerProfileId;

    #[Locked]
    public string $monthKey;

    public function mount(int $owner, string $month): void
    {
        $this->ownerProfileId = $owner;
        $this->monthKey = ($found = StatementMonth::fromKey($month)) !== null ? $found->key() : abort(404);
    }

    public function render(OwnerPortalAccess $portal, OwnerStatements $statements, OrganizationContext $context): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $month = StatementMonth::fromKey($this->monthKey) ?? abort(404);

        $page = $portal->within($user, $this->ownerProfileId, static fn (PortalOwner $owner): array => [
            'owner' => $owner,
            'agency' => PortalAgency::of($context->get()),
            'statement' => $statements->month($owner->ownerProfileId, $month),
        ]) ?? abort(404);

        return view('ownerstatements::livewire.portal.statement-month', $page);
    }
}
