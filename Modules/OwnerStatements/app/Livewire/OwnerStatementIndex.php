<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Statements\OwnerStatement;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\StatementMonth;
use Modules\OwnerStatements\Statements\StatementTermsBook;
use Modules\Properties\Contracts\OwnerProfileDirectory;

/**
 * An owner's statements in the agency's workspace,
 * /o/{slug}/owners/{owner}/statements (P08-05): the owner's terms
 * (commission and utilities pass to the owner; changed by owners and
 * administrators; owners always see their tenants' details since P03-16), the last 12 months with their
 * figures and Settled / Unsettled (export), and the leases ending in the
 * next 90 days. OwnerStatementPolicy on every render and action.
 */
#[Layout('layouts::app')]
#[Title('Owner statements')]
final class OwnerStatementIndex extends Component
{
    use WithListExports;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $ownerProfileId;

    /** Percentage as typed ("10", "7.5"); blank = not set. */
    public string $commission = '';

    public bool $utilitiesToOwner = false;

    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, OwnerStatements $statements, int $owner): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewOwner', [OwnerStatementSettlement::class, $owner]);
        $this->ownerProfileId = $owner;
        $this->fillTerms($statements);
    }

    #[BlockedWhileImpersonating]
    public function saveTerms(StatementTermsBook $book, OwnerStatements $statements): void
    {
        $this->authorize('changeTerms', [OwnerStatementSettlement::class, $this->ownerProfileId]);

        $book->change($this->user(), $this->ownerProfileId, [
            'commission' => $this->commission,
            'utilitiesToOwner' => $this->utilitiesToOwner,
        ]);

        $this->fillTerms($statements);
        $this->notice = __('The terms were saved. They apply to every month not yet settled.');
    }

    public function render(OrganizationContext $context, OwnerStatements $statements, OwnerProfileDirectory $owners): View
    {
        $user = $this->user();
        $this->authorize('viewOwner', [OwnerStatementSettlement::class, $this->ownerProfileId]);

        return view('ownerstatements::livewire.owner-statement-index', [
            'organization' => $context->get(),
            'owner' => $owners->find($this->ownerProfileId) ?? abort(404),
            'months' => $statements->months($this->ownerProfileId, StatementMonth::current()->lastMonths()),
            'renewals' => $statements->renewals($this->ownerProfileId),
            'canManage' => $user->can('changeTerms', [OwnerStatementSettlement::class, $this->ownerProfileId]),
        ]);
    }

    protected function listExports(): array
    {
        return ['owner_statements' => ['owner' => $this->ownerProfileId]];
    }

    private function fillTerms(OwnerStatements $statements): void
    {
        $terms = $statements->terms($this->ownerProfileId);
        $this->commission = $terms->commissionBps === null ? '' : rtrim(OwnerStatement::rate($terms->commissionBps), '%');
        $this->utilitiesToOwner = $terms->utilitiesToOwner;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
