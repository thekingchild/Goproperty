<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Transfers;

use App\Enums\InvitationKind;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Enums\BillingTransferStatus;
use Modules\SaasBilling\Models\BillingTransferRequest;
use SensitiveParameter;

/**
 * The link emailed to the receiving organization's owners,
 * /billing-transfers/{token}: a Core invitation of kind
 * `org_billing_transfer` (P01-04's table and token handling: only the
 * token's SHA-256 is stored; valid 14 days).
 *
 * Found as P01-04 finds member invitations: the organization is read from
 * the invitation row by the token's hash (one unscoped column), then the
 * invitation and its transfer are loaded inside that (the offering)
 * organization's context. The first opening is recorded; a pending offer
 * past its date is recorded as expired (through BillingTransferService).
 */
final readonly class TransferLinks
{
    public function __construct(
        private OrganizationContext $context,
        private BillingTransferService $transfers,
    ) {}

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The transfer behind a link, whatever its state, for a user who works
     * in the receiving organization; null for an unknown link or anyone
     * else (nothing is recorded for them).
     */
    public function open(#[SensitiveParameter] string $token, User $user): ?BillingTransferRequest
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $organizationId = DB::table('invitations')
            ->where('token_hash', self::hash($token))
            ->where('kind', InvitationKind::OrgBillingTransfer->value)
            ->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if ($organization === null) {
            return null;
        }

        return $this->context->run($organization, function () use ($token, $organization, $user): ?BillingTransferRequest {
            $invitation = Invitation::query()
                ->where('token_hash', self::hash($token))
                ->where('kind', InvitationKind::OrgBillingTransfer)
                ->first();
            $transfer = $invitation?->billing_transfer_id !== null
                ? BillingTransferRequest::query()->with('toOrganization')->find($invitation->billing_transfer_id)
                : null;

            if ($invitation === null || $transfer === null || ! in_array($transfer->to_organization_id, $user->workspaceOrganizationIds(), true)) {
                return null;
            }

            if ($invitation->opened_at === null) {
                $invitation->forceFill(['opened_at' => now()])->save();
            }

            if ($transfer->status === BillingTransferStatus::Pending && $transfer->isPastExpiry()) {
                try {
                    $this->transfers->expire($transfer);
                } catch (BillingTransferRefused) {
                    // Answered a moment ago: show it as it is now.
                }

                $transfer = $transfer->refresh()->load('toOrganization');
            }

            return $transfer->setRelation('organization', $organization);
        });
    }
}
