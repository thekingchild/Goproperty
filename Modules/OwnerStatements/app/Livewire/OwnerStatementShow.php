<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Livewire;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\Settlements;
use Modules\OwnerStatements\Statements\StatementMonth;
use Modules\Properties\Contracts\OwnerProfileDirectory;

/**
 * One owner's month in the agency's workspace,
 * /o/{slug}/owners/{owner}/statements/{YYYY-MM} (P08-05): the statement
 * (the snapshot once settled), the PDF, and for owners and administrators
 * "Mark settled" (date paid, reference) or "Mark unsettled" (reason).
 * GoProperty records the settlement only; the agency pays the owner
 * outside it. Statements\Settlements checks the policy again and audits.
 */
#[Layout('layouts::app')]
#[Title('Owner statement')]
final class OwnerStatementShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $ownerProfileId;

    #[Locked]
    public string $monthKey;

    public string $paidOn = '';

    public string $reference = '';

    public string $reason = '';

    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, int $owner, string $month): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewOwner', [OwnerStatementSettlement::class, $owner]);
        $this->ownerProfileId = $owner;
        $this->monthKey = ($found = StatementMonth::fromKey($month)) !== null ? $found->key() : abort(404);
    }

    #[BlockedWhileImpersonating]
    public function settle(Settlements $settlements): void
    {
        $this->authorize('settle', [OwnerStatementSettlement::class, $this->ownerProfileId]);
        $settlements->settle($this->user(), $this->ownerProfileId, $this->month(), $this->paidOn, $this->reference);
        $this->reset('paidOn', 'reference', 'reason');
        $this->notice = __(':month is marked settled. Its figures are kept as they are now.', ['month' => $this->month()->label()]);
    }

    #[BlockedWhileImpersonating]
    public function unsettle(Settlements $settlements): void
    {
        $this->authorize('unsettle', [OwnerStatementSettlement::class, $this->ownerProfileId]);
        $settlements->unsettle($this->user(), $this->ownerProfileId, $this->month(), $this->reason);
        $this->reset('paidOn', 'reference', 'reason');
        $this->notice = __(':month is marked unsettled. Its figures are worked out from the records again.', ['month' => $this->month()->label()]);
    }

    public function render(OrganizationContext $context, OwnerStatements $statements, OwnerProfileDirectory $owners): View
    {
        $user = $this->user();
        $this->authorize('viewOwner', [OwnerStatementSettlement::class, $this->ownerProfileId]);
        $statement = $statements->month($this->ownerProfileId, $this->month());
        $people = User::query()->whereKey(array_values(array_filter([$statement->settlement?->settled_by_user_id, $statement->settlement?->unsettled_by_user_id])))->pluck('name', 'id')->all();

        return view('ownerstatements::livewire.owner-statement-show', [
            'organization' => $context->get(),
            'owner' => $owners->find($this->ownerProfileId) ?? abort(404),
            'statement' => $statement,
            'people' => $people,
            'canManage' => $user->can('settle', [OwnerStatementSettlement::class, $this->ownerProfileId]),
        ]);
    }

    private function month(): StatementMonth
    {
        return StatementMonth::fromKey($this->monthKey) ?? abort(404);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
