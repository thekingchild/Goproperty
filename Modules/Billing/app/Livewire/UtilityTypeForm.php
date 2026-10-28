<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire;

use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Livewire\Concerns\InUtilityWorkspace;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\UtilityInput;
use Modules\Billing\Utilities\UtilityTypes;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * Add a utility type (/o/{organization}/utilities/types/create) or edit an
 * active one (/utilities/types/{utilityType}/edit) (P03-06a): name, code,
 * charging (flat, or metered with a unit of measure), default amount (per
 * cycle, or per unit of measure) and default billing cycle. Once units use
 * a type, its charging stays as it is (Utilities\UtilityTypes).
 *
 * UtilityTypePolicy::create / update (`utilities.manage`: owners and
 * administrators), checked again by the writer. {utilityType} resolves
 * inside the organization only (404 otherwise).
 */
#[Layout('layouts::app')]
#[Title('Utility')]
final class UtilityTypeForm extends Component
{
    use InUtilityWorkspace;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $typeId = null;

    public string $name = '';

    public string $code = '';

    public string $chargingMode = 'flat';

    public string $unitOfMeasure = '';

    /** Naira as typed. */
    public string $amount = '';

    public string $cycle = 'monthly';

    public function mount(OrganizationContext $context, ?UtilityType $utilityType = null): void
    {
        $this->organizationId = $context->id();

        if ($utilityType === null || ! $utilityType->exists) {
            $this->authorize('create', UtilityType::class);

            return;
        }

        $this->authorize('update', $utilityType);
        $this->typeId = $utilityType->id;
        $this->name = $utilityType->name;
        $this->code = $utilityType->code;
        $this->chargingMode = $utilityType->charging_mode->value;
        $this->unitOfMeasure = $utilityType->unit_of_measure->value ?? '';
        $this->amount = UtilityInput::nairaInput($utilityType->default_amount_kobo);
        $this->cycle = $utilityType->default_cycle->value;
    }

    public function save(UtilityTypes $types): void
    {
        $input = [
            'name' => $this->name,
            'code' => $this->code,
            'chargingMode' => $this->chargingMode,
            'unitOfMeasure' => $this->unitOfMeasure,
            'amount' => $this->amount,
            'cycle' => $this->cycle,
        ];

        if ($this->typeId === null) {
            $this->authorize('create', UtilityType::class);
            $type = $types->create($this->user(), $input);
            session()->flash('utility-status', __(':name is added. Choose which units pay it on "Units\' utilities".', ['name' => $type->name]));
        } else {
            $type = UtilityType::query()->findOrFail($this->typeId);
            $this->authorize('update', $type);
            $types->update($this->user(), $type, $input);
            session()->flash('utility-status', __(':name is saved.', ['name' => $type->name]));
        }

        $this->redirectRoute('workspace.utilities.index', ['organization' => $this->organizationSlug()], navigate: true);
    }

    public function render(OrganizationContext $context, UtilityTypes $types): View
    {
        $type = $this->typeId !== null ? UtilityType::query()->findOrFail($this->typeId) : null;

        if ($type !== null) {
            // Checked on every render, not only when the page opened.
            $this->authorize('update', $type);
        }

        return view('billing::livewire.utility-type-form', [
            'organization' => $context->get(),
            'type' => $type,
            'chargingLocked' => $type !== null && $types->inUse($type),
            'chargingModes' => ChargingMode::cases(),
            'meterUnits' => MeterUnit::cases(),
            'cycles' => BillingCycle::cases(),
        ]);
    }
}
