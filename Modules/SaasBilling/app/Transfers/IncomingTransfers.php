<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Transfers;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Models\BillingTransferRequest;

/**
 * The receiving side's view of billing transfers: the offers addressed to
 * the current organization (its invite inbox and accept screen).
 *
 * A transfer row belongs to the offering organization, so the receiving
 * one cannot query it through the organization scope. As P01-04 does for
 * invitation links (P02 decision 2), this reads only `id` and
 * `organization_id` of the rows whose `to_organization_id` is the current
 * organization (taken from OrganizationContext, never from the request),
 * then loads each row inside its own organization's context. No scope is
 * removed; a row addressed to another organization is simply not found.
 * Permission checks stay with the caller (BillingTransferRequestPolicy).
 */
final readonly class IncomingTransfers
{
    public function __construct(private OrganizationContext $context) {}

    /** The offer addressed to the current organization with this id, with its offering organization loaded. */
    public function find(int $transferId): ?BillingTransferRequest
    {
        return $this->load($this->addressedHere()->where('id', $transferId)->limit(1))->first();
    }

    /**
     * Offers addressed to the current organization, newest first: the
     * open ones, then the most recent answered, withdrawn or expired.
     *
     * @return Collection<int, BillingTransferRequest>
     */
    public function recent(int $limit = 20): Collection
    {
        return $this->load($this->addressedHere()->orderByDesc('id')->limit($limit))
            ->sortBy(static fn (BillingTransferRequest $transfer): array => [$transfer->isOpen() ? 0 : 1, -$transfer->id])
            ->values();
    }

    /**
     * Every offer addressed to the current organization, in the same order
     * as recent() (the export of the invite inbox, P02-07). An organization
     * receives a handful of offers (offering is rate-limited), so they are
     * read at once.
     *
     * @return Collection<int, BillingTransferRequest>
     */
    public function all(): Collection
    {
        return $this->load($this->addressedHere()->orderByDesc('id'))
            ->sortBy(static fn (BillingTransferRequest $transfer): array => [$transfer->isOpen() ? 0 : 1, -$transfer->id])
            ->values();
    }

    private function addressedHere(): Builder
    {
        return DB::table('billing_transfer_requests')->where('to_organization_id', $this->context->id());
    }

    /** @return Collection<int, BillingTransferRequest> */
    private function load(Builder $rows): Collection
    {
        $receiver = $this->context->id();
        $byOrganization = $rows->get(['id', 'organization_id'])
            ->groupBy(static fn (object $row): int => (int) $row->organization_id);

        $transfers = new Collection;

        foreach (Organization::query()->whereKey($byOrganization->keys()->all())->get() as $organization) {
            $ids = $byOrganization->get($organization->id, new Collection)->map(static fn (object $row): int => (int) $row->id)->all();

            $loaded = $this->context->run($organization, static fn () => BillingTransferRequest::query()
                ->with(['initiatedBy', 'respondedBy'])
                ->whereKey($ids)
                ->where('to_organization_id', $receiver)
                ->get());

            foreach ($loaded as $transfer) {
                $transfers->push($transfer->setRelation('organization', $organization));
            }
        }

        return $transfers;
    }
}
