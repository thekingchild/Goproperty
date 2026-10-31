<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\SaasBilling\Contracts\BankTransferReviews;
use Modules\SaasBilling\Contracts\TransferReviewRefused;

/**
 * The console's side of reviewing a bank-transfer proof (P06-04;
 * BILLING_PAYMENTS.md § 5 steps 3–4): checks the staff member's permission
 * (BankTransferProofPolicy), then hands the decision to SaasBilling
 * (Contracts\BankTransferReviews), which checks it again and writes the
 * payment, the invoice and the audit entry in one transaction. Also makes
 * the short-lived signed link to the proof's file (the link is the
 * permission until it expires, so it is made only for staff who may see it).
 */
final readonly class BankTransferReview
{
    public function __construct(private OrganizationContext $context) {}

    /**
     * @throws AuthorizationException
     * @throws TransferReviewRefused
     */
    public function confirm(PlatformStaff $staff, BankTransferProof $proof): void
    {
        if (! $staff->can('review', $proof)) {
            throw new AuthorizationException;
        }

        $this->reviews()->confirm($staff, $proof->id);
    }

    /**
     * @throws AuthorizationException
     * @throws TransferReviewRefused
     */
    public function reject(PlatformStaff $staff, BankTransferProof $proof, string $reason): void
    {
        if (! $staff->can('review', $proof)) {
            throw new AuthorizationException;
        }

        $this->reviews()->reject($staff, $proof->id, $reason);
    }

    /** A link to the proof's file, valid for a few minutes; null when the staff member may not see it or it is missing. */
    public function fileUrl(PlatformStaff $staff, BankTransferProof $proof): ?string
    {
        if (! $staff->can('view', $proof) || $proof->bank_transfer_proof_media_id === null) {
            return null;
        }

        $organization = Organization::query()->find($proof->organization_id);

        if (! $organization instanceof Organization) {
            return null;
        }

        return $this->context->run($organization, static function () use ($proof): ?string {
            $media = Media::query()->find($proof->bank_transfer_proof_media_id);

            return $media instanceof Media ? app(MediaUrls::class)->temporary($media) : null;
        });
    }

    private function reviews(): BankTransferReviews
    {
        if (! app()->bound(BankTransferReviews::class)) {
            throw new TransferReviewRefused('Bank transfers cannot be reviewed: the SaasBilling module is not enabled.');
        }

        return app(BankTransferReviews::class);
    }
}
