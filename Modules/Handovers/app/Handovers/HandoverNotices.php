<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationOwners;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverChanged;
use Modules\Handovers\Notifications\HandoverOffered;

/**
 * Who hears about a handover's changes (BILLING_PAYMENTS.md § 7.7 rule 3):
 * the owners (with an email address) of both organizations, once each per
 * change, by email and in the app. Someone who owns both organizations
 * hears once, from the side that sent it first. The offer itself carries
 * the accept screen's link to the receiving owners only.
 */
final readonly class HandoverNotices
{
    public function __construct(private OrganizationOwners $owners) {}

    /**
     * @param  list<string>  $offeringLines  what the offering side is told
     * @param  list<string>|null  $receivingLines  what the receiving side is told (the same when null)
     */
    public function tell(ManagementHandover $handover, string $event, string $subject, array $offeringLines, ?array $receivingLines = null): void
    {
        $offering = $handover->organization;
        $receiving = $handover->toOrganization;
        $told = $this->send($offering, new HandoverChanged($event, $handover->id, $offering->slug, $subject, $offeringLines), []);
        $this->send($receiving, new HandoverChanged($event, $handover->id, $receiving->slug, $subject, $receivingLines ?? $offeringLines), $told);
    }

    /**
     * A new offer: its link to the receiving owners, word of it to the offering ones.
     *
     * @param  list<string>  $offeringLines
     */
    public function offered(ManagementHandover $handover, HandoverOffered $offer, string $subject, array $offeringLines): void
    {
        $told = $this->send($handover->toOrganization, $offer, []);
        $this->send($handover->organization, new HandoverChanged('handover.offered', $handover->id, $handover->organization->slug, $subject, $offeringLines), $told);
    }

    /**
     * @param  list<int>  $skip  users already told
     * @return list<int> everyone told so far
     */
    private function send(Organization $organization, object $notification, array $skip): array
    {
        /** @var Collection<int, User> $owners */
        $owners = $this->owners->withEmail($organization)->reject(static fn (User $user): bool => in_array($user->id, $skip, true))->values();

        if ($owners->isNotEmpty()) {
            Notification::send($owners, $notification);
        }

        return [...$skip, ...$owners->map(static fn (User $user): int => $user->id)->values()->all()];
    }
}
