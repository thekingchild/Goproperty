<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Contracts\BillLedger;
use Modules\Handovers\Enums\CreditSettlement;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Models\HandoverTenant;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Leasing\Contracts\LiveLeases;
use Modules\Payments\Contracts\CreditChanged;
use Modules\Payments\Contracts\HandoverCredits as TenantCreditLedger;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The moving tenants' credit at a management handover (P03 decision 11,
 * BILLING_PAYMENTS.md § 7.4). Credit is money a tenant paid into the
 * landlord's account, so it never moves by itself and is never counted
 * twice:
 *
 * - the handover lists each moving tenant with their unused credit and the
 *   bills they still owe the landlord (which stay with the landlord, as
 *   history), refreshed when it is offered and again when it is accepted;
 * - the landlord chooses, per tenant, "pass to the agency" (the default)
 *   or "refund the tenant", and may change it until it is settled;
 * - "refund the tenant": the landlord pays it back outside GoProperty and
 *   records the refund, which closes the credit on the landlord's books;
 * - "pass to the agency": the landlord pays it to the agency outside
 *   GoProperty; once the agency confirms the money arrived, one
 *   transaction closes it on the landlord's books and records it as the
 *   tenant's opening credit at the agency.
 *
 * Until then the credit stays on the landlord's books, shown to both
 * organizations on the handover's page. Only org-owners act
 * (ManagementHandoverPolicy::settleCredit, blocked while staff
 * impersonate); the amount acted on is the one shown, refused when it
 * changed. Every settlement is audited and both sides are told.
 */
final readonly class HandoverCredits
{
    public function __construct(
        private OrganizationContext $context,
        private LiveLeases $leases,
        private TenantDirectory $tenants,
        private TenantCreditLedger $credits,
        private BillLedger $bills,
        private HandoverNotices $notices,
    ) {}

    /**
     * Lists the tenants moving with these buildings on the handover, with
     * their credit and unpaid bills now, keeping the landlord's choices.
     * Inside the offering organization's context and the caller's
     * transaction, while the handover is pending.
     *
     * @param  list<int>  $propertyIds
     */
    public function refresh(ManagementHandover $handover, array $propertyIds): void
    {
        $ids = $this->leases->tenantIdsIn($propertyIds);
        $names = $this->tenants->findMany($ids);
        $credits = $this->credits->balances($ids);
        $lines = HandoverTenant::query()->where('management_handover_id', $handover->id)->get()->keyBy('tenant_profile_id');

        foreach ($ids as $tenantId) {
            $open = $this->bills->openBills($tenantId);
            $line = $lines->get($tenantId) ?? new HandoverTenant;
            $line->forceFill([
                'management_handover_id' => $handover->id,
                'tenant_profile_id' => $tenantId,
                'tenant_name' => mb_substr($names[$tenantId]->name ?? __('A tenant'), 0, 170),
                'credit_kobo' => $credits[$tenantId] ?? Money::zero(),
                'unpaid_bills' => count($open),
                'unpaid_kobo' => Money::sum(array_map(static fn ($bill): Money => $bill->outstanding, $open)),
            ])->save();
        }

        HandoverTenant::query()
            ->where('management_handover_id', $handover->id)
            ->whereNotIn('tenant_profile_id', $ids === [] ? [0] : $ids)
            ->delete();
    }

    /**
     * Each line's credit on the landlord's books now (zero when used or
     * settled), read in the landlord's organization.
     *
     * @return array<int, Money> by line id
     */
    public function liveCredits(ManagementHandover $handover): array
    {
        $landlord = Organization::query()->findOrFail($handover->landlord_organization_id);
        $lines = $handover->tenants;
        $balances = $this->context->run($landlord, fn (): array => $this->credits->balances(array_values($lines->pluck('tenant_profile_id')->map(static fn (mixed $id): int => (int) $id)->all())));

        return $lines->mapWithKeys(static fn (HandoverTenant $line): array => [$line->id => $line->isSettled() ? Money::zero() : ($balances[$line->tenant_profile_id] ?? Money::zero())])->all();
    }

    /**
     * The landlord chooses how a tenant's credit is settled.
     *
     * @throws HandoverRefused
     */
    public function choose(User $actor, ManagementHandover $handover, int $lineId, CreditSettlement $choice): void
    {
        $this->asLandlord($actor, $handover, function (ManagementHandover $locked, HandoverTenant $line) use ($actor, $choice): void {
            if (! in_array($locked->status, [HandoverStatus::Pending, HandoverStatus::Active, HandoverStatus::Ending, HandoverStatus::Ended], true)) {
                throw new HandoverRefused(__('This handover is closed: nothing moved, so there is no credit to settle.'));
            }

            if ($line->settlement === $choice) {
                return;
            }

            $line->forceFill(['settlement' => $choice])->save();
            $this->audit('handover.credit_choice', $locked, $actor, "Chose to {$choice->label()} for {$line->tenant_name}", ['tenant_profile_id' => $line->tenant_profile_id, 'settlement' => $choice->value]);
        }, $lineId);
    }

    /**
     * The landlord records that it refunded the tenant's credit outside
     * GoProperty: the credit is closed on its books.
     *
     * @throws HandoverRefused
     */
    public function refund(User $actor, ManagementHandover $handover, int $lineId, Money $shown): void
    {
        $this->asLandlord($actor, $handover, function (ManagementHandover $locked, HandoverTenant $line, Organization $landlord) use ($actor, $shown): void {
            if (! $locked->status->wasAccepted()) {
                throw new HandoverRefused(__('The credit is settled once the handover is accepted.'));
            }

            if ($line->settlement !== CreditSettlement::Refund) {
                throw new HandoverRefused(__('Choose "Refund the tenant" first.'));
            }

            $this->closeAt($landlord, $line, $shown, true, $actor, __('Refunded to the tenant at the management handover to :agency.', ['agency' => $locked->toOrganization->name]));
            $this->settled($locked, $line, $shown, $actor, $landlord, 'handover.credit_refunded', "Refunded {$line->tenant_name}'s credit of {$shown->format()}");
        }, $lineId);
    }

    /**
     * The agency confirms the landlord's payment of the tenant's credit
     * arrived (outside GoProperty): one transaction closes it on the
     * landlord's books and records it as the tenant's opening credit here.
     *
     * @throws HandoverRefused
     */
    public function confirmArrived(User $actor, ManagementHandover $handover, int $lineId, Money $shown): void
    {
        Gate::forUser($actor)->authorize('settleCredit', $handover);
        $agency = $this->context->get();

        if ($agency->id !== $handover->to_organization_id) {
            throw new HandoverRefused(__('Only the agency managing the buildings confirms that the money arrived.'));
        }

        $offering = Organization::query()->findOrFail($handover->organization_id);
        $landlord = Organization::query()->findOrFail($handover->landlord_organization_id);

        DB::transaction(fn () => $this->context->run($offering, function () use ($actor, $handover, $lineId, $shown, $agency, $landlord): void {
            [$locked, $line] = $this->lock($handover, $lineId);

            if (! in_array($locked->status, [HandoverStatus::Active, HandoverStatus::Ending], true)) {
                throw new HandoverRefused(__('Credit is passed to the agency while it manages the buildings.'));
            }

            if ($line->settlement !== CreditSettlement::PassToAgency) {
                throw new HandoverRefused(__(':landlord chose to refund this tenant instead.', ['landlord' => $landlord->name]));
            }

            if ($line->receiving_tenant_profile_id === null) {
                throw new HandoverRefused(__('This tenant did not move with the buildings.'));
            }

            $this->closeAt($landlord, $line, $shown, false, $actor, __('Passed to :agency at the management handover.', ['agency' => $agency->name]));
            $this->context->run($agency, fn () => $this->credits->open((int) $line->receiving_tenant_profile_id, $shown, $actor, __('Credit passed on by :landlord at the management handover, received outside GoProperty.', ['landlord' => $landlord->name])));
            $this->settled($locked, $line, $shown, $actor, $agency, 'handover.credit_passed_on', "{$agency->name} confirmed {$line->tenant_name}'s credit of {$shown->format()} arrived");
        }));
    }

    /**
     * @param  callable(ManagementHandover, HandoverTenant, Organization): void  $step
     *
     * @throws HandoverRefused
     */
    private function asLandlord(User $actor, ManagementHandover $handover, callable $step, int $lineId): void
    {
        Gate::forUser($actor)->authorize('settleCredit', $handover);
        $landlord = $this->context->get();

        if ($landlord->id !== $handover->landlord_organization_id) {
            throw new HandoverRefused(__('Only the landlord chooses how a tenant\'s credit is settled.'));
        }

        $offering = Organization::query()->findOrFail($handover->organization_id);

        DB::transaction(fn () => $this->context->run($offering, function () use ($handover, $lineId, $step, $landlord): void {
            [$locked, $line] = $this->lock($handover, $lineId);
            $step($locked, $line, $landlord);
        }));
    }

    /**
     * @return array{ManagementHandover, HandoverTenant}
     *
     * @throws HandoverRefused
     */
    private function lock(ManagementHandover $handover, int $lineId): array
    {
        $locked = ManagementHandover::query()->with(['organization', 'toOrganization'])->whereKey($handover->id)->lockForUpdate()->firstOrFail();
        $line = HandoverTenant::query()->where('management_handover_id', $locked->id)->whereKey($lineId)->lockForUpdate()->first()
            ?? throw new HandoverRefused(__('This tenant is not part of the handover.'));

        if ($line->isSettled()) {
            throw new HandoverRefused(__(':name\'s credit was already settled.', ['name' => $line->tenant_name]));
        }

        return [$locked, $line];
    }

    /** @throws HandoverRefused */
    private function closeAt(Organization $landlord, HandoverTenant $line, Money $shown, bool $refunded, User $actor, string $note): void
    {
        try {
            $this->context->run($landlord, fn () => $this->credits->close($line->tenant_profile_id, $shown, $refunded, $actor, $note));
        } catch (CreditChanged $changed) {
            throw new HandoverRefused($changed->getMessage());
        }
    }

    private function settled(ManagementHandover $handover, HandoverTenant $line, Money $amount, User $actor, Organization $by, string $event, string $description): void
    {
        $line->forceFill([
            'settled_kobo' => $amount,
            'settled_at' => now(),
            'settled_by_user_id' => $actor->id,
            'settled_by_organization_id' => $by->id,
        ])->save();

        $this->audit($event, $handover, $actor, $description, ['tenant_profile_id' => $line->tenant_profile_id, 'receiving_tenant_profile_id' => $line->receiving_tenant_profile_id, 'amount_kobo' => $amount->kobo]);
        $this->notices->tell($handover, $event, __('Tenant credit settled: :name', ['name' => $line->tenant_name]), [$description.'.']);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, ManagementHandover $handover, User $actor, string $description, array $properties): void
    {
        activity(HandoverService::LOG)
            ->event($event)
            ->performedOn($handover)
            ->causedBy($actor)
            ->withProperties([
                'handover_id' => $handover->id,
                'from_organization_id' => $handover->organization_id,
                'to_organization_id' => $handover->to_organization_id,
                ...$properties,
            ])
            ->log($description);
    }
}
