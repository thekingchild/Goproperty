<?php

declare(strict_types=1);

namespace Modules\Collections\Livewire;

use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Livewire\Concerns\InWorkspace;
use Modules\Collections\Matching\LineMatches;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Collections\Statements\StatementAmount;
use Modules\Collections\Statements\UnreadableRow;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Match a statement credit by hand,
 * /o/{organization}/statements/{statement}/lines/{line}/match (P03-07b;
 * ONBOARDING_COMPLIANCE.md § 4.1 "otherwise unmatched, for manual
 * allocation"; product owner decision 10: owners and administrators
 * only). The credit (date, amount, narration), a tenant, and optionally
 * how much of it goes to each of the tenant's open bills — one credit can
 * pay several bills; whatever is left goes to the tenant's credit. Without
 * amounts, the tenant's open bills are settled oldest first. Saving
 * records the payment and confirms it at once (Matching\LineMatches::
 * matchByHand, through Payments' recorder): a credit is matched once.
 * BankStatementImportPolicy::match on opening and on saving; never while
 * staff impersonate.
 */
#[Layout('layouts::app')]
#[Title('Match a credit')]
final class LineMatch extends Component
{
    use InWorkspace;

    #[Locked]
    public int $statementId;

    #[Locked]
    public int $lineId;

    public string $tenantId = '';

    /** @var array<string, string> bill id => naira as typed */
    public array $amounts = [];

    public function mount(OrganizationContext $context, BankStatementImport $statement, BankStatementLine $line): void
    {
        $this->enterWorkspace($context);
        $this->authorize('match', $statement);
        abort_unless($line->bank_statement_import_id === $statement->id && $line->direction === LineDirection::Credit, 404);
        $this->statementId = $statement->id;
        $this->lineId = $line->id;
    }

    /** Another tenant chosen: their bills, not the last one's. */
    public function updatedTenantId(): void
    {
        $this->amounts = [];
        $this->resetErrorBag();
    }

    #[BlockedWhileImpersonating]
    public function save(OrganizationContext $context, LineMatches $matches, TenantDirectory $tenants): void
    {
        $statement = BankStatementImport::query()->findOrFail($this->statementId);
        $this->authorize('match', $statement);

        if (! ctype_digit($this->tenantId) || ! array_key_exists((int) $this->tenantId, $tenants->activeOptions($this->user()))) {
            throw ValidationException::withMessages(['tenantId' => __('Choose a tenant.')]);
        }

        $split = $this->split();

        try {
            $matches->matchByHand($this->user(), $this->lineId, (int) $this->tenantId, $split === [] ? null : $split);
        } catch (ValidationException $refused) {
            // Keys of the payment step (payment, billId, …) are shown at the top.
            $this->setErrorBag($refused->validator->errors());

            return;
        }

        session()->flash('statement-status', __('The credit was matched and its payment confirmed. Receipts go to the tenant.'));
        $this->redirectRoute('workspace.statements.show', ['organization' => $context->get()->slug, 'statement' => $statement->id], navigate: true);
    }

    public function render(OrganizationContext $context, TenantDirectory $tenants, BillLedger $ledger): View
    {
        $statement = BankStatementImport::query()->findOrFail($this->statementId);
        // Checked on every render, not only when the page opened.
        $this->authorize('match', $statement);
        $line = BankStatementLine::query()->findOrFail($this->lineId);
        $options = $tenants->activeOptions($this->user());
        $bills = ctype_digit($this->tenantId) && array_key_exists((int) $this->tenantId, $options)
            ? $ledger->openBills((int) $this->tenantId)
            : [];

        return view('collections::livewire.line-match', [
            'organization' => $context->get(),
            'statement' => $statement,
            'line' => $line,
            'open' => $line->isOfferedForMatching(),
            'tenants' => $options,
            'bills' => $bills,
            'owed' => Money::sum(array_map(static fn (BillBalance $bill): Money => $bill->outstanding, $bills)),
        ]);
    }

    /**
     * The amounts typed for bills, read as exact kobo.
     *
     * @return array<int, Money> bill id => amount
     *
     * @throws ValidationException
     */
    private function split(): array
    {
        $split = [];

        foreach ($this->amounts as $billId => $typed) {
            $typed = trim((string) $typed);

            if ($typed === '') {
                continue;
            }

            try {
                $read = ctype_digit((string) $billId) ? StatementAmount::read($typed, __('amount')) : null;
            } catch (UnreadableRow) {
                $read = null;
            }

            if ($read === null || $read['kobo'] <= 0 || $read['mark'] !== null) {
                throw ValidationException::withMessages(["amounts.{$billId}" => __('Enter an amount in naira, such as 150,000.')]);
            }

            $split[(int) $billId] = Money::kobo($read['kobo']);
        }

        return $split;
    }
}
