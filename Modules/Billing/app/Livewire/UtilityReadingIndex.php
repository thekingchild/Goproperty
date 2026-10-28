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
use Modules\Billing\Lists\UtilityReadingList;
use Modules\Billing\Lists\UtilityRows;
use Modules\Billing\Livewire\Concerns\InUtilityWorkspace;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Meter readings, /o/{organization}/utilities/readings (P03-06a): kept
 * readings, newest first, 25 a page, filtered by building, utility and one
 * unit's meter (`?meter=`, from the units' utilities list), all in the URL;
 * exported through Lists\UtilityReadingList. Each row: the date, the unit,
 * previous → current and the consumption, "meter replaced", billed or not,
 * who recorded it and the meter photo; the latest reading of a meter not
 * yet billed can be corrected or removed (UtilityReadingForm).
 *
 * UtilityReadingPolicy: `utilities.view` sees the page (an assigned agent:
 * their buildings only); `utilities.record_readings` records readings.
 */
#[Layout('layouts::app')]
#[Title('Meter readings')]
final class UtilityReadingIndex extends Component
{
    use InUtilityWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $utility = '';

    /** One unit's meter (a setting id), or empty. */
    #[Url(except: '')]
    public string $meter = '';

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', UtilityReading::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['building', 'utility', 'meter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('building', 'utility', 'meter');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, UtilityReadingList $list, UtilityRows $rows, PropertyDirectory $buildings): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['utility_readings']);
        $readings = $list->query($user, $filters)->paginate(self::PER_PAGE);
        $readings->getCollection()->load('media');
        $canRecord = $user->can('create', UtilityReading::class);
        // The latest kept reading of each meter on the page: the only ones
        // that may be corrected (UtilityReadingPolicy::update checks again on
        // the form).
        $latest = $canRecord ? array_map(static fn (UtilityReading $reading): int => $reading->id, $rows->latestReadings(array_values(array_unique($readings->getCollection()->map(static fn (UtilityReading $reading): int => $reading->unit_utility_setting_id)->all())))) : [];

        return view('billing::livewire.utility-reading-index', [
            'organization' => $context->get(),
            'readings' => $readings,
            'rows' => $rows->readings($readings->getCollection()),
            'filtered' => $filters !== [],
            'buildings' => $buildings->activeOptions($user),
            'utilities' => UtilityType::query()->where('charging_mode', 'metered')->orderByDesc('active')->orderBy('name')->get(['id', 'name', 'active']),
            'canRecord' => $canRecord,
            'correctable' => array_values($latest),
            'canSeeSettings' => $user->can('viewAny', UnitUtilitySetting::class),
        ]);
    }

    protected function listExports(): array
    {
        return ['utility_readings' => [
            'building' => $this->building,
            'utility' => $this->utility,
            'meter' => $this->meter,
        ]];
    }
}
