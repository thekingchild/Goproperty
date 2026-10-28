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
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Lists\UtilityTypeList;
use Modules\Billing\Livewire\Concerns\InUtilityWorkspace;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\UtilityTypes;

/**
 * Utilities, /o/{organization}/utilities (P03-06a; ARCHITECTURE.md § 6.4):
 * the organization's utility types (security levy, water, electricity…),
 * 25 a page, active first, with a search over name and code and filters by
 * status and charging, all in the URL; exported through
 * Lists\UtilityTypeList. Archive and restore here; add and edit on
 * UtilityTypeForm. Links to the units' utilities and the meter readings.
 *
 * UtilityTypePolicy: `utilities.view` sees the page (owners,
 * administrators, agents); `utilities.manage` (owners, administrators)
 * adds, edits, archives and restores, each checked again by
 * Utilities\UtilityTypes.
 */
#[Layout('layouts::app')]
#[Title('Utilities')]
final class UtilityTypeIndex extends Component
{
    use InUtilityWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $charging = '';

    #[Locked]
    public ?string $notice = null;

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', UtilityType::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'charging'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'charging');
        $this->resetPage();
    }

    public function archive(int $typeId, UtilityTypes $types): void
    {
        $type = UtilityType::query()->findOrFail($typeId);
        $this->authorize('delete', $type);
        $types->archive($this->user(), $type);
        $this->notice = __(':name is archived. Units are no longer charged it; their settings and readings are kept.', ['name' => $type->name]);
    }

    public function restore(int $typeId, UtilityTypes $types): void
    {
        $type = UtilityType::query()->findOrFail($typeId);
        $this->authorize('restore', $type);
        $types->restore($this->user(), $type);
        $this->notice = __(':name is active again.', ['name' => $type->name]);
    }

    public function render(OrganizationContext $context, UtilityTypeList $list): View
    {
        $filters = $list->filters($this->listExports()['utility_types']);
        $user = $this->user();

        return view('billing::livewire.utility-type-index', [
            'organization' => $context->get(),
            'types' => $list->query($user, $filters)->paginate(self::PER_PAGE),
            'filtered' => $filters !== [],
            'chargingModes' => ChargingMode::cases(),
            'canCreate' => $user->can('create', UtilityType::class),
            'canSeeSettings' => $user->can('viewAny', UnitUtilitySetting::class),
            'canSeeReadings' => $user->can('viewAny', UtilityReading::class),
        ]);
    }

    protected function listExports(): array
    {
        return ['utility_types' => [
            'search' => $this->search,
            'status' => $this->status,
            'charging' => $this->charging,
        ]];
    }
}
