<?php

declare(strict_types=1);

namespace Modules\Leasing\Documents;

use App\Documents\BrandingSnapshot;
use App\Documents\BrandingSnapshots;
use App\Documents\DocumentRenderer;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Verification\VerificationCodes;
use App\Media\Media;
use App\Models\Organization;
use App\Settings\SettingsStore;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Lease contracts (P03-03b; BRANDING_DOCUMENTS.md § 4–5, ARCHITECTURE.md
 * § 6.5): the only writer of `lease_contracts`.
 *
 * - issue(): once a lease is active (Listeners\IssueLeaseContract, right
 *   after the activation commits; a renewal's activation issues the
 *   renewal's contract), records the contract as issued: the agency's
 *   branding snapshot (images embedded), the parties and the rent schedule,
 *   the agency's clause template, and a verification code (kind
 *   `lease_contract`). Once per lease, under the lease's row lock.
 * - pdf(): the contract's PDF, rendered the first time only
 *   (DocumentRenderer::stored(), key `lease-contract`) from that record and
 *   kept as an evidence file; every re-print returns the kept file. Queued
 *   on `pdf` by Jobs\RenderLeaseContract; the download renders it on the
 *   spot only when the queue has not got to it yet.
 *
 * Later changes to the branding, the clause template, the tenant's or the
 * building's details never change an issued contract.
 */
final readonly class LeaseContracts
{
    /** The verification kind registered in DocumentVerifiers. */
    public const string KIND = 'lease_contract';

    /** The kept PDF's key (DocumentRenderer::stored()). */
    public const string DOCUMENT_KEY = 'lease-contract';

    public function __construct(
        private OrganizationContext $context,
        private BrandingSnapshots $snapshots,
        private VerificationCodes $codes,
        private SettingsStore $settings,
        private TenantDirectory $tenants,
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
        private CycleCalculator $calculator,
        private DocumentRenderer $renderer,
    ) {}

    /** The contract issued for this lease, if any. */
    public function forLease(Lease $lease): ?LeaseContract
    {
        return LeaseContract::query()->where('lease_id', $lease->id)->first();
    }

    /**
     * The lease's contract, recorded now if it was not yet (idempotent).
     * The lease must have its contract number (it has been activated).
     */
    public function issue(Lease $lease): LeaseContract
    {
        return DB::transaction(function () use ($lease): LeaseContract {
            $lease = Lease::query()->withTrashed()->whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $existing = $this->forLease($lease);

            if ($existing instanceof LeaseContract) {
                return $existing;
            }

            if ($lease->contract_number === null) {
                throw new LogicException('A lease gets its contract once it is activated (it has no contract number yet).');
            }

            $organization = Organization::query()->findOrFail($this->context->id());

            $contract = new LeaseContract;
            $contract->forceFill([
                'lease_id' => $lease->id,
                'contract_number' => $lease->contract_number,
                'verification_code' => $this->codes->issue(self::KIND),
                'branding_snapshot' => $this->snapshots->take($organization)->toArray(),
                'details' => $this->details($lease),
                'clauses' => $this->clauseTemplate(),
                'issued_at' => $lease->activated_at ?? CarbonImmutable::now(),
            ])->save();

            return $contract;
        }, 3);
    }

    /** The contract as printed, from what was recorded at issue. */
    public function document(LeaseContract $contract): LeaseContractDocument
    {
        $details = $contract->details;
        $tenant = self::map($details['tenant'] ?? null);
        $building = self::map($details['building'] ?? null);
        $terms = self::map($details['terms'] ?? null);

        return new LeaseContractDocument(
            brand: BrandingSnapshot::fromArray($contract->branding_snapshot)->documentBranding(),
            number: $contract->contract_number,
            issuedAt: $contract->issued_at,
            tenant: new DocumentTenant(self::text($tenant['name'] ?? null) ?? '', self::text($tenant['phone'] ?? null), self::text($tenant['email'] ?? null)),
            property: new DocumentProperty(self::text($building['name'] ?? null) ?? '', self::text($details['unit'] ?? null), self::text($building['address'] ?? null)),
            landlord: self::text($details['landlord'] ?? null),
            cycle: BillingCycle::from(self::text($terms['billing_cycle'] ?? null) ?? ''),
            startDate: self::date($terms['start_date'] ?? null),
            endDate: self::date($terms['end_date'] ?? null),
            rent: Money::kobo(self::integer($terms['rent_kobo'] ?? null)),
            deposit: Money::kobo(self::integer($terms['deposit_kobo'] ?? null)),
            dueDayOffset: self::integer($terms['due_day_offset'] ?? null),
            firstDue: self::date($terms['first_due'] ?? null),
            paidUntil: isset($terms['paid_until']) ? self::date($terms['paid_until']) : null,
            renewalOf: self::text($details['renewal_of'] ?? null),
            clauseTemplate: $contract->clauses,
            verificationCode: $contract->verification_code,
        );
    }

    /**
     * The contract's PDF: the kept file, or rendered and kept now (once,
     * under a lock on the contract row). Records its media id on the row.
     */
    public function pdf(LeaseContract $contract): Media
    {
        $media = $this->renderer->stored($contract, self::DOCUMENT_KEY, $this->document($contract));

        if ($contract->document_media_id === null) {
            LeaseContract::query()->whereKey($contract->id)->whereNull('document_media_id')->update(['document_media_id' => $media->id]);
        }

        return $media;
    }

    /** The kept PDF, if it has been rendered. */
    public function kept(LeaseContract $contract): ?Media
    {
        return $this->renderer->kept($contract, self::DOCUMENT_KEY);
    }

    /** The organization's clause template now (Settings → Lease contract). */
    public function clauseTemplate(): string
    {
        $template = $this->settings->get(ContractClauses::SETTING);

        return is_string($template) && trim($template) !== '' ? $template : ContractClauses::DEFAULT;
    }

    /**
     * The parties and the rent schedule as they are now (at issue).
     *
     * @return array<string, mixed>
     */
    private function details(Lease $lease): array
    {
        $tenant = $this->tenants->find($lease->tenant_profile_id);
        $unit = $this->units->find($lease->unit_id);
        $building = $this->buildings->find($lease->property_id);
        $firstPeriod = $lease->next_billing_date ?? $lease->start_date;

        return [
            'tenant' => [
                'name' => $tenant !== null ? $tenant->name : __('Unknown tenant'),
                'phone' => $tenant?->phone,
                'email' => $tenant?->email,
            ],
            'building' => [
                'name' => $building !== null ? $building->name : ($unit !== null ? $unit->propertyName : __('Removed building')),
                'address' => $building !== null ? self::address($building->address, $building->state) : null,
            ],
            'unit' => $unit !== null ? $unit->label : null,
            'landlord' => $building?->ownerName,
            'renewal_of' => $lease->renewal_of_lease_id !== null ? Lease::query()->withTrashed()->whereKey($lease->renewal_of_lease_id)->value('contract_number') : null,
            'terms' => [
                'billing_cycle' => $lease->billing_cycle->value,
                'start_date' => $lease->start_date->toDateString(),
                'end_date' => $lease->end_date->toDateString(),
                'rent_kobo' => $lease->rent_amount_kobo->kobo,
                'deposit_kobo' => $lease->deposit_kobo->kobo,
                'due_day_offset' => $lease->due_day_offset,
                'first_due' => $this->calculator->dueDate($firstPeriod, $lease->due_day_offset)->toDateString(),
                'paid_until' => $lease->paid_until?->toDateString(),
            ],
        ];
    }

    private static function address(string $address, string $state): string
    {
        $address = trim($address);

        return $state === '' || str_contains(mb_strtolower($address), mb_strtolower($state)) ? $address : ($address === '' ? $state : $address.', '.$state);
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private static function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new LogicException('An issued lease contract is missing one of its dates.');
        }

        return CarbonImmutable::parse($value, 'Africa/Lagos')->startOfDay();
    }
}
