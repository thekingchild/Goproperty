<?php

declare(strict_types=1);

namespace Modules\Leasing\Http\Controllers;

use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Leasing\Documents\LeaseContracts;
use Modules\Leasing\Models\Lease;

/**
 * /o/{organization}/leases/{lease}/contract: the lease's contract PDF
 * (P03-03b; ARCHITECTURE.md § 6.5 "lease activation (and re-print)").
 * Checks LeasePolicy::downloadContract, then sends the member to a
 * short-lived signed link of the kept file (App\Media\MediaUrls, ARCHITECTURE.md
 * § 9). Re-printing serves the file stored at the first print, never a new
 * render. When the `pdf` queue has not rendered it yet, it is rendered
 * now, once (the same lock as the queued job); a lease activated before
 * contracts existed gets its contract issued on its first download.
 *
 * {lease} resolves inside the organization only (the `organization`
 * middleware runs first), so another organization's lease answers 404.
 */
final class LeaseContractController
{
    public function __invoke(Request $request, Organization $organization, Lease $lease, LeaseContracts $contracts, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('downloadContract', $lease);

        $contract = $contracts->forLease($lease) ?? $contracts->issue($lease);

        return redirect()->away($urls->temporary($contracts->pdf($contract)));
    }
}
