<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Onboarding\OrganizationDetails;
use App\Support\NigerianStates;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** The organization fields of signup (ONBOARDING_COMPLIANCE.md § 2 step 2). */
final class OrganizationForm extends Form
{
    public string $type = 'agency';

    public string $name = '';

    public string $state = '';

    public string $lga = '';

    public string $cacNumber = '';

    public function fillFrom(Organization $organization): void
    {
        $this->type = $organization->type->value;
        $this->name = $organization->name;
        $this->state = $organization->state ?? '';
        $this->lga = $organization->lga ?? '';
        $this->cacNumber = $organization->cac_number ?? '';
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'state' => ['required', Rule::in(NigerianStates::ALL)],
            'lga' => ['nullable', 'string', 'max:64'],
            // Optional at Tier 1; CAC numbers look like RC 1234567 or BN 1234567.
            'cacNumber' => ['nullable', 'string', 'max:20', 'regex:/^(RC|BN|IT|LP|LLP)?\s?\d{1,9}$/i'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'type' => 'type',
            'name' => 'organization name',
            'state' => 'state',
            'lga' => 'LGA',
            'cacNumber' => 'CAC number',
        ];
    }

    public function details(): OrganizationDetails
    {
        $this->validate();

        $lga = trim($this->lga);
        $cac = strtoupper((string) preg_replace('/\s+/', ' ', trim($this->cacNumber)));

        return new OrganizationDetails(
            OrganizationType::from($this->type),
            trim($this->name),
            $this->state,
            $lga !== '' ? $lga : null,
            $cac !== '' ? $cac : null,
        );
    }
}
