<?php

declare(strict_types=1);

namespace Modules\Leasing\Livewire;

use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Leasing\Documents\ContractClauses;
use Modules\Leasing\Documents\LeaseContracts;

/**
 * Settings → Lease contract, /o/{organization}/settings/documents/lease-contract
 * (P03-03b; BRANDING_DOCUMENTS.md § 4 "clauses (agency-editable
 * template)"): the clauses printed on every new lease contract, as the
 * organization setting `leasing.contract_clauses` (ContractClauses), with
 * the whitelisted placeholders and a preview. Org-owners and
 * administrators see it (`viewSettings`, read-only while suspended) and
 * save it (`updateBranding`, like the rest of how documents look). Saving
 * and restoring the default are audited by the settings store. Contracts
 * already issued keep the clauses they were issued with.
 *
 * The template is plain text: it is never compiled or evaluated, and the
 * contract prints it escaped (AGENT.md § 3 rule 10).
 */
#[Layout('layouts::app')]
#[Title('Lease contract')]
final class ContractSettingsPage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $clauses = '';

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, LeaseContracts $contracts): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewSettings', $organization);
        $this->clauses = $contracts->clauseTemplate();
    }

    public function save(OrganizationContext $context, SettingsStore $settings): void
    {
        $organization = $context->get();
        $this->authorize('updateBranding', $organization);
        $this->clauses = trim(str_replace(["\r\n", "\r"], "\n", $this->clauses));
        $this->validate(['clauses' => ['required', 'string', 'max:'.ContractClauses::MAX_LENGTH]], [], ['clauses' => __('clauses')]);

        $settings->set(ContractClauses::SETTING, $this->clauses, $organization);
        $this->status = __('Saved. New lease contracts will print these clauses; contracts already issued keep theirs.');
    }

    public function restoreDefault(OrganizationContext $context, SettingsStore $settings): void
    {
        $organization = $context->get();
        $this->authorize('updateBranding', $organization);

        $settings->forget(ContractClauses::SETTING, $organization);
        $this->clauses = ContractClauses::DEFAULT;
        $this->resetValidation();
        $this->status = __('The standard clauses are back. New lease contracts will print them.');
    }

    public function render(OrganizationContext $context): View
    {
        $organization = $context->get();

        return view('leasing::livewire.contract-settings-page', [
            'canEdit' => $this->user()->can('updateBranding', $organization),
            'placeholders' => ContractClauses::PLACEHOLDERS,
            'unknown' => ContractClauses::unknownPlaceholders($this->clauses),
            'preview' => ContractClauses::render($this->clauses, $this->sampleValues($organization)),
            'maxLength' => ContractClauses::MAX_LENGTH,
        ]);
    }

    /**
     * Sample values for the preview: never a real tenant's details.
     *
     * @return array<string, string>
     */
    private function sampleValues(Organization $organization): array
    {
        $rent = Money::naira(1_500_000);

        return [
            'tenant_name' => 'Chiamaka Okafor',
            'tenant_phone' => '+234 812 345 6789',
            'landlord' => 'Mr Babatunde Adeyemi',
            'agency_name' => $organization->name,
            'property' => 'Palm Court Apartments',
            'unit' => 'Flat 3B',
            'address' => '22 Fola Osibo Road, Lekki Phase 1, Lagos',
            'rent' => $rent->format(),
            'rent_in_words' => $rent->inWords(),
            'cycle' => 'yearly',
            'deposit' => Money::naira(150_000)->format(),
            'start_date' => '1 Jan 2027',
            'end_date' => '31 Dec 2027',
            'contract_number' => 'LS-ADR-2027-000012',
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
