<?php

declare(strict_types=1);

namespace Modules\Leasing\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Leases\LeaseDates;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Leases\LeaseTerms;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Draft a lease (/o/{organization}/leases/create, optionally
 * `?unit={id}&tenant={id}`), change a draft (/leases/{id}/edit) or draft
 * the renewal of an active lease (/leases/{id}/renew). `leases.manage`
 * (LeasePolicy, checked again by Leases\LeaseService). Tenants come from
 * the Tenants module's directory and buildings and units from the
 * Properties module's (narrowed to an assigned agent's), never their
 * tables. Rent is per period of the billing cycle, typed in naira and kept
 * as kobo. A draft's tenant and unit are fixed once it is made, and a
 * renewal's are its lease's.
 *
 * The organization and lease ids are locked and the lease loaded through
 * the organization scope on every request; another organization's lease
 * answers 404.
 */
#[Layout('layouts::app')]
#[Title('Lease')]
final class LeaseForm extends Component
{
    #[Locked]
    public int $organizationId;

    /** The draft being changed. */
    #[Locked]
    public ?int $leaseId = null;

    /** The active lease being renewed. */
    #[Locked]
    public ?int $renewingId = null;

    #[Url(as: 'tenant', except: '')]
    public string $tenantId = '';

    public string $propertyId = '';

    #[Url(as: 'unit', except: '')]
    public string $unitId = '';

    public string $billingCycle = 'annual';

    public string $startDate = '';

    public string $endDate = '';

    /** Rent for one period of the cycle, in naira as typed. */
    public string $rent = '';

    public string $deposit = '';

    public string $dueDayOffset = '0';

    public string $paidUntil = '';

    public bool $autoRenew = false;

    public string $notes = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, CycleCalculator $calculator, UnitDirectory $units, BuildingAccess $access, ?Lease $lease = null): void
    {
        $this->organizationId = $context->id();

        if ($lease === null) {
            $this->authorize('create', Lease::class);
            $this->startDate = LeaseDates::today()->toDateString();
            $this->endDate = $calculator->period(LeaseDates::today(), BillingCycle::Annual, 0)->end->toDateString();
            $this->prefillUnit($units, $access);

            return;
        }

        if (request()->routeIs('workspace.leases.renew')) {
            $this->authorize('renew', $lease);
            $this->renewingId = $lease->id;
            $this->fillFrom($lease, $calculator);

            return;
        }

        $this->authorize('update', $lease);
        $this->leaseId = $lease->id;
        $this->fillFrom($lease);
    }

    /** A building chosen: its first unit is not assumed. */
    public function updatedPropertyId(): void
    {
        $this->unitId = '';
    }

    /** A unit chosen: a yearly lease starts from the unit's asking rent. */
    public function updatedUnitId(): void
    {
        $unit = ctype_digit($this->unitId) ? app(UnitDirectory::class)->find((int) $this->unitId) : null;

        if ($unit !== null && app(BuildingAccess::class)->allows($this->user(), $unit->propertyId)
            && trim($this->rent) === '' && $this->billingCycle === BillingCycle::Annual->value && $unit->baseRent->isPositive()) {
            $this->rent = LeaseTerms::nairaInput($unit->baseRent);
        }
    }

    public function save(LeaseService $leases): void
    {
        $user = $this->user();
        $input = [
            'tenantId' => ctype_digit($this->tenantId) ? (int) $this->tenantId : null,
            'unitId' => ctype_digit($this->unitId) ? (int) $this->unitId : null,
            'billingCycle' => $this->billingCycle,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'rent' => $this->rent,
            'deposit' => $this->deposit,
            'dueDayOffset' => $this->dueDayOffset,
            'paidUntil' => $this->paidUntil,
            'autoRenew' => $this->autoRenew,
            'notes' => $this->notes,
        ];

        if ($this->leaseId !== null) {
            $lease = $this->lease($this->leaseId);
            $this->authorize('update', $lease);
            $lease = $leases->update($user, $lease, $input);
            session()->flash('lease-status', __('The draft lease was saved.'));
        } elseif ($this->renewingId !== null) {
            $previous = $this->lease($this->renewingId);
            $this->authorize('renew', $previous);
            $lease = $leases->renew($user, $previous, $input);
            session()->flash('lease-status', __('The renewal was drafted. Activate it once it is signed: :contract then becomes renewed.', ['contract' => $previous->reference()]));
        } else {
            $this->authorize('create', Lease::class);
            $lease = $leases->draft($user, $input);
            session()->flash('lease-status', __('The lease was drafted. The unit is held for this tenant until :date.', ['date' => $lease->reserved_until?->format('d/m/Y')]));
        }

        $this->redirectRoute('workspace.leases.show', ['organization' => $this->organizationSlug(), 'lease' => $lease->id], navigate: true);
    }

    public function render(OrganizationContext $context, TenantDirectory $tenants, PropertyDirectory $buildings, UnitDirectory $units, LeaseService $leases): View
    {
        $user = $this->user();
        $fixed = $this->leaseId ?? $this->renewingId;
        $lease = $fixed !== null ? $this->lease($fixed) : null;

        if ($lease !== null) {
            $this->authorize($this->leaseId !== null ? 'update' : 'renew', $lease);
        }

        $unit = $lease !== null ? $units->find($lease->unit_id) : null;
        $tenant = $lease !== null ? $tenants->find($lease->tenant_profile_id) : null;

        return view('leasing::livewire.lease-form', [
            'organization' => $context->get(),
            'lease' => $lease,
            'renewing' => $this->renewingId !== null,
            'fixedTenant' => $tenant?->name,
            'fixedUnit' => $unit !== null ? $unit->label.' · '.$unit->propertyName : null,
            'tenants' => $lease === null ? $tenants->activeOptions($user) : [],
            'buildings' => $lease === null ? $buildings->activeOptions($user) : [],
            'units' => $lease === null && ctype_digit($this->propertyId) ? $units->inProperty((int) $this->propertyId) : [],
            'cycles' => BillingCycle::cases(),
            'reservationDays' => $leases->reservationDays(),
            'carriedDeposit' => $this->renewingId !== null && $lease !== null ? $leases->depositBalance($lease) : null,
        ]);
    }

    /** ?unit= from a unit's page: its building is chosen too, if the member works on it. */
    private function prefillUnit(UnitDirectory $units, BuildingAccess $access): void
    {
        $unit = ctype_digit($this->unitId) ? $units->find((int) $this->unitId) : null;

        if ($unit === null || $unit->propertyArchived || ! $access->allows($this->user(), $unit->propertyId)) {
            $this->unitId = '';

            return;
        }

        $this->propertyId = (string) $unit->propertyId;

        if ($unit->baseRent->isPositive()) {
            $this->rent = LeaseTerms::nairaInput($unit->baseRent);
        }
    }

    /** The form from a lease: its draft terms, or (renewing) the terms of the next lease. */
    private function fillFrom(Lease $lease, ?CycleCalculator $renewal = null): void
    {
        $this->billingCycle = $lease->billing_cycle->value;
        $this->rent = LeaseTerms::nairaInput($lease->rent_amount_kobo);
        $this->dueDayOffset = (string) $lease->due_day_offset;
        $this->autoRenew = $lease->auto_renew;

        if ($renewal === null) {
            $this->startDate = $lease->start_date->toDateString();
            $this->endDate = $lease->end_date->toDateString();
            $this->deposit = LeaseTerms::nairaInput($lease->deposit_kobo, emptyWhenZero: true);
            $this->paidUntil = $lease->paid_until?->toDateString() ?? '';
            $this->notes = $lease->notes ?? '';

            return;
        }

        // The renewal starts the day after the lease ends and runs as many periods.
        $start = $renewal->daysAfter($lease->end_date, 1);
        $periods = max(1, $renewal->periodsBetween($lease->start_date, $lease->billing_cycle, $lease->start_date, $start));
        $this->startDate = $start->toDateString();
        $this->endDate = $renewal->period($start, $lease->billing_cycle, $periods - 1)->end->toDateString();
    }

    private function lease(int $id): Lease
    {
        return Lease::query()->findOrFail($id);
    }

    private function organizationSlug(): string
    {
        return app(OrganizationContext::class)->get()->slug;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
