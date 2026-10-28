<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Enums\InvitationKind;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Models\ManagementHandover;
use SensitiveParameter;

/**
 * The link emailed to the receiving organization's owners,
 * /handovers/{token}: a Core invitation of kind `org_billing_transfer`
 * with `management_handover_id` (P01-04's table and token handling: only
 * the token's SHA-256 is stored; valid 14 days).
 *
 * Found as P01-04 finds member invitations: the organization is read from
 * the invitation row by the token's hash (one unscoped column), then the
 * invitation and its handover are loaded inside that (the offering)
 * organization's context. The first opening is recorded; a pending offer
 * past its date is recorded as expired (through HandoverService).
 */
final readonly class HandoverLinks
{
    public function __construct(
        private OrganizationContext $context,
        private HandoverService $handovers,
    ) {}

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The handover behind a link, whatever its state, for a user who works
     * in the receiving organization; null for an unknown link or anyone
     * else (nothing is recorded for them).
     */
    public function open(#[SensitiveParameter] string $token, User $user): ?ManagementHandover
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $organizationId = DB::table('invitations')
            ->where('token_hash', self::hash($token))
            ->where('kind', InvitationKind::ManagementHandover->value)
            ->whereNotNull('management_handover_id')
            ->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if ($organization === null) {
            return null;
        }

        return $this->context->run($organization, function () use ($token, $organization, $user): ?ManagementHandover {
            $invitation = Invitation::query()
                ->where('token_hash', self::hash($token))
                ->where('kind', InvitationKind::ManagementHandover)
                ->first();
            $handover = $invitation?->management_handover_id !== null
                ? ManagementHandover::query()->with('toOrganization')->find($invitation->management_handover_id)
                : null;

            if ($invitation === null || $handover === null || ! in_array($handover->to_organization_id, $user->workspaceOrganizationIds(), true)) {
                return null;
            }

            if ($invitation->opened_at === null) {
                $invitation->forceFill(['opened_at' => now()])->save();
            }

            if ($handover->status === HandoverStatus::Pending && $handover->isPastExpiry()) {
                try {
                    $this->handovers->expire($handover);
                } catch (HandoverRefused) {
                    // Answered a moment ago: show it as it is now.
                }

                $handover = $handover->refresh()->load('toOrganization');
            }

            return $handover->setRelation('organization', $organization);
        });
    }
}
