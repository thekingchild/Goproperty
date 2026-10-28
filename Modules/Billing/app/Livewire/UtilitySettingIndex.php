<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Billing\Lists\UtilityRows;
use Modules\Billing\Lists\UtilitySettingList;
use Modules\Billing\Livewire\Concerns\InUtilityWorkspace;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Units' utilities, /o/{organization}/utilities/units (P03-06a): which
 * utilities each unit is charged, the amount and cycle (the unit's own or
 * the utility's default), on or off, and a metered utility's latest
 * reading; 25 a page, filtered by building, utility, status and one unit
 * (`?unit=`), all in the URL; exported through Lists\UtilitySettingList.
 * Unit and building names are read in one lookup per page
 * (Lists\UtilityRows, no N+1).
 *
 * UnitUtilitySettingPolicy: `utilities.view` sees the page (an assigned
 * agent: their buildings only, and only those in the building filter);
 * `utilities.manage` adds utilities to units and edits them
 * (UtilitySettingForm); `recordReading` links to the reading form.
 */
#[Layout('layouts::app')]
#[Title('Units\' utilities')]
final class UtilitySettingIndex extends Component
{
    use InUtilityWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $utility = '';

    #[Url(except: '')]
    public string $status = '';

    /** One unit (`?unit=`), or empty. */
    #[Url(except: '')]
    public string $unit = '';

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', UnitUtilitySetting::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['building', 'utility', 'status', 'unit'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('building', 'utility', 'status', 'unit');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, UtilitySettingList $list, UtilityRows $rows, PropertyDirectory $buildings): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['utility_settings']);
        $settings = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('billing::livewire.utility-setting-index', [
            'organization' => $context->get(),
            'settings' => $settings,
            'rows' => $rows->settings($settings->getCollection()),
            'filtered' => $filters !== [],
            'buildings' => $buildings->activeOptions($user),
            'utilities' => UtilityType::query()->orderByDesc('active')->orderBy('name')->get(['id', 'name', 'active']),
            'canCreate' => $user->can('create', UnitUtilitySetting::class),
            'canSeeReadings' => $user->can('viewAny', UtilityReading::class),
            // Once for the page (the rows are already narrowed); the forms
            // check UnitUtilitySettingPolicy::update / recordReading again.
            'canRecord' => $user->can('create', UtilityReading::class),
        ]);
    }

    protected function listExports(): array
    {
        return ['utility_settings' => [
            'building' => $this->building,
            'utility' => $this->utility,
            'status' => $this->status,
            'unit' => $this->unit,
        ]];
    }
}
