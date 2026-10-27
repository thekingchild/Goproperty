<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Media\Media;

/**
 * How the tenant portal (P03-11b) reads a tenant's own proofs of payment
 * and where each stands (Contracts\PaymentProofs submits them): never the
 * Payments module's models or tables (ARCHITECTURE.md § 8 rule 3).
 *
 * Every method takes the tenant profile ids the portal proved are the
 * signed-in user's in the current organization and answers only for proofs
 * of those tenants — the ones they sent from the portal and the ones a
 * member uploaded for them; any other id is not found (null). Never says
 * whether the same file was sent elsewhere (that flag is the reviewer's).
 * Answers for the current organization only. No permission check: the
 * caller checks its policy before it opens a file. Bound in
 * PaymentsServiceProvider.
 */
interface TenantProofs
{
    /**
     * Their proofs, archived ones too, newest first.
     *
     * @param  list<int>  $tenantProfileIds
     * @return list<TenantProof>
     */
    public function forTenants(array $tenantProfileIds): array;

    /**
     * One of their proofs' file (kept as evidence, archived proofs too), or null.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function file(int $proofId, array $tenantProfileIds): ?Media;
}
