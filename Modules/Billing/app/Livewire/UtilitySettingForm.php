<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire;

use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Billing\Bills\BillDates;
use Modules\Billing\Lists\UtilityRows;
use Modules\Billing\Livewire\Concerns\InUtilityWorkspace;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\UnitUtilitySettings;
use Modules\Billing\Utilities\UtilityInput;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * Add a utility to units (/o/{organization}/utilities/units/add, optionally
 * `?building=` and `?utility=`) or change one unit's utility
 * (/utilities/units/{setting}/edit) (P03-06a): the building, the utility
 * and the units (every unit of the building, or some), and the overrides —
 * amount and cycle (empty: the utility's default), the date it applies
 * from and a metered utility's starting reading; on edit, switch it on or
 * off. Units that have the utility already are skipped and named.
 *
 * UnitUtilitySettingPolicy::create / update (`utilities.manage`: owners and
 * administrators), checked again with the building's narrowing by
 * Utilities\UnitUtilitySettings. {setting} resolves inside the
 * organization only.
 */
#[Layout('layouts::app')]
#[Title('Unit utility')]
final class UtilitySettingForm extends Component
{
    use InUtilityWorkspace;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $settingId = null;

    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $utility = '';

    /** @var list<string> unit ids */
    public array $units = [];

    /** Naira as typed; empty for the utility's default. */
    public string $amount = '';

    /** A cycle value; empty for the utility's default. */
    public string $cycle = '';

    public string $effectiveFrom = '';

    public string $startingReading = '';

    public bool $active = true;

    public function mount(OrganizationContext $context, ?UnitUtilitySetting $setting = null): void
    {
        $this->organizationId = $context->id();

        if ($setting === null || ! $setting->exists) {
            $this->authorize('create', UnitUtilitySetting::class);
            $this->effectiveFrom = BillDates::today()->toDateString();
            $this->updatedBuilding();

            return;
        }

        $this->authorize('update', $setting);
        $this->settingId = $setting->id;
        $this->amount = UtilityInput::nairaInput($setting->amount_kobo);
        $this->cycle = $setting->cycle->value ?? '';
        $this->effectiveFrom = $setting->effective_from->toDateString();
        $this->startingReading = $setting->starting_reading?->input() ?? '';
        $this->active = $setting->active;
    }

    /** Another building: every one of its units chosen. */
    public function updatedBuilding(): void
    {
        $this->units = $this->offeredUnitIds();
    }

    public function save(UnitUtilitySettings $settings): void
    {
        $terms = [
            'amount' => $this->amount,
            'cycle' => $this->cycle,
            'effectiveFrom' => $this->effectiveFrom,
            'startingReading' => $this->startingReading,
        ];

        if ($this->settingId === null) {
            $this->authorize('create', UnitUtilitySetting::class);
            $added = $settings->add($this->user(), [...$terms, 'building' => $this->building, 'utilityType' => $this->utility, 'units' => $this->units]);
            $message = trans_choice('The utility is added to :count unit.|The utility is added to :count units.', count($added->created), ['count' => count($added->created)]);

            if ($added->skipped !== []) {
                $message .= ' '.__('Already paying it, left as they were: :units.', ['units' => implode(', ', $added->skipped)]);
            }

            session()->flash('utility-status', $message);
            $this->redirectRoute('workspace.utilities.settings.index', ['organization' => $this->organizationSlug(), 'building' => $this->building], navigate: true);

            return;
        }

        $setting = UnitUtilitySetting::query()->findOrFail($this->settingId);
        $this->authorize('update', $setting);
        $settings->update($this->user(), $setting, [...$terms, 'active' => $this->active]);
        session()->flash('utility-status', __('The unit\'s utility is saved.'));
        $this->redirectRoute('workspace.utilities.settings.index', ['organization' => $this->organizationSlug(), 'unit' => $setting->unit_id], navigate: true);
    }

    public function render(OrganizationContext $context, PropertyDirectory $buildings, UnitDirectory $units, UtilityRows $rows): View
    {
        $user = $this->user();
        $setting = $this->settingId !== null ? UnitUtilitySetting::query()->with('utilityType')->findOrFail($this->settingId) : null;

        if ($setting !== null) {
            // Checked on every render, not only when the page opened.
            $this->authorize('update', $setting);
        }

        $type = $setting->utilityType ?? (ctype_digit($this->utility) ? UtilityType::query()->where('active', true)->find((int) $this->utility) : null);

        return view('billing::livewire.utility-setting-form', [
            'organization' => $context->get(),
            'setting' => $setting,
            'row' => $setting !== null ? $rows->settings([$setting])[0] : null,
            'hasReadings' => $setting !== null && UtilityReading::query()->where('unit_utility_setting_id', $setting->id)->exists(),
            'type' => $type,
            'buildings' => $buildings->activeOptions($user),
            'unitOptions' => $setting === null && ctype_digit($this->building) && array_key_exists((int) $this->building, $buildings->activeOptions($user)) ? $units->inProperty((int) $this->building) : [],
            'utilities' => UtilityType::query()->where('active', true)->orderBy('name')->get(),
            'cycles' => BillingCycle::cases(),
        ]);
    }

    /** @return list<string> */
    private function offeredUnitIds(): array
    {
        $buildings = app(PropertyDirectory::class);

        if (! ctype_digit($this->building) || ! array_key_exists((int) $this->building, $buildings->activeOptions($this->user()))) {
            return [];
        }

        return array_map('strval', array_keys(app(UnitDirectory::class)->inProperty((int) $this->building)));
    }
}
