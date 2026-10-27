<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * How the tenant portal (P03-11b, the Tenants module's /portal pages)
 * submits a tenant's proof of payment (P03-09; ONBOARDING_COMPLIANCE.md
 * § 4.1 "Proof upload stays as the tenant-side fallback"): never the
 * Payments module's models or tables (ARCHITECTURE.md § 8 rule 3).
 *
 * Call it inside the bill's organization (OrganizationContext::run, the
 * organization found through the tenant's linked profiles): another
 * organization's bill is simply not found. The tenant is proven by their
 * portal account (`tenant_profiles.user_id`, P03-01b): only the tenant the
 * bill was issued to may submit for it (anyone else: 404). It keeps working
 * while the organization is suspended (ONBOARDING_COMPLIANCE.md § 3), and
 * is refused while GoProperty staff impersonate the user. Bound in
 * PaymentsServiceProvider.
 */
interface PaymentProofs
{
    /** At most this many proofs for one bill an hour, and for one tenant a day (ARCHITECTURE.md § 10). */
    public const int PER_BILL_PER_HOUR = 5;

    public const int PER_TENANT_PER_DAY = 20;

    /** The collection proof files are kept in (config `media.collections`): JPEG, PNG, WebP or PDF, at most 10 MB. */
    public const string COLLECTION = 'payment_proofs';

    /** Whether $tenant may submit a proof for the bill now (to show the form): theirs, not void, not paid. */
    public function maySubmit(User $tenant, int $billId): bool;

    /**
     * Submit the tenant's proof for one of their bills. It records a payment
     * waiting for the agency's confirmation (source `proof_upload`), with the
     * file attached; the same file uploaded before in the organization is
     * flagged for the reviewer (never refused for it).
     *
     * $input: `amount` (naira as typed, "150,000"), `paidOn` (Y-m-d, not after
     * today), `senderName` (whose account paid), `bank` (the bank paid from),
     * optional `reference` (the narration or session ID) and `note`.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException 404 for a bill that is not the tenant's (or not in this organization), 403 otherwise
     * @throws ValidationException keyed by the input names, `file`, or `proof` (too many proofs: try later)
     */
    public function submitForBill(User $tenant, int $billId, array $input, UploadedFile $file): SubmittedProof;
}
