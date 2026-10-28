<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire;

use App\Documents\QrCode;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Leasing\Contracts\ImportedLeases;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Portal\PortalAccessRefused;
use Modules\Tenants\Portal\TenantInvitations;

/**
 * "Invite tenants now" on a committed import's report (DATA_IMPORT.md
 * § 2.6, P03-13a; registered into Import's ImportReportPanels): an import
 * never contacts anyone, so after reviewing it the agency may invite the
 * import's tenants who have no portal account and no invitation yet, all
 * at once, by email, by its WhatsApp, or as a printable sheet of QR
 * invitation links. Each invitation goes through P03-01b's
 * TenantInvitations (one per tenant, 14 days, single use, policy
 * `managePortalAccess`, audited, rate limited): a tenant the limits or
 * the policy turn away is counted, never forced.
 *
 * The import's tenants are those it created or brought back, and the
 * tenants of the leases it brought in (Leasing's ImportedLeases). Links
 * for the printable sheet are shown once (only their hashes are kept),
 * held in a locked property until the agency closes the sheet. Every
 * action is refused while GoProperty staff impersonate.
 */
final class ImportInvitations extends Component
{
    /** At most this many invitations in one go (the rate limits allow 30 an hour per member). */
    public const int BATCH = 30;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $batchId;

    /** @var list<array{name: string, phone: string, url: string}> the printable sheet's links, shown once */
    #[Locked]
    public array $sheet = [];

    #[Locked]
    public ?string $notice = null;

    #[Locked]
    public ?string $problem = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, int $batchId): void
    {
        $this->authorize('viewAny', TenantProfile::class);
        $this->organizationId = $context->id();
        $this->batchId = $batchId;
    }

    #[BlockedWhileImpersonating]
    public function inviteByEmail(TenantInvitations $invitations): void
    {
        $this->inviteAll($invitations, InvitationChannel::Email);
    }

    #[BlockedWhileImpersonating]
    public function inviteByWhatsApp(TenantInvitations $invitations): void
    {
        $this->inviteAll($invitations, InvitationChannel::WhatsApp);
    }

    #[BlockedWhileImpersonating]
    public function printSheet(TenantInvitations $invitations): void
    {
        $this->inviteAll($invitations, InvitationChannel::Link);
    }

    /** Hides the sheet: its links cannot be shown again (send again from a tenant's page). */
    public function closeSheet(): void
    {
        $this->sheet = [];
    }

    public function render(OrganizationContext $context, TenantInvitations $invitations): View
    {
        $this->authorize('viewAny', TenantProfile::class);
        $tenants = $this->tenants();
        $invited = $this->invited($tenants);
        $waiting = $tenants->reject(fn (TenantProfile $tenant): bool => isset($invited[$tenant->id]));
        $channels = $waiting->isNotEmpty() ? $invitations->channels($waiting->first()) : [];

        return view('tenants::livewire.import-invitations', [
            'organization' => $context->get(),
            'total' => $tenants->count(),
            'waiting' => $waiting->count(),
            'withEmail' => $waiting->filter(fn (TenantProfile $tenant): bool => $tenant->email !== null)->count(),
            'whatsApp' => in_array(InvitationChannel::WhatsApp, $channels, true),
            'canInvite' => $waiting->isNotEmpty() && $this->user()->can('managePortalAccess', $waiting->first()),
            'qr' => array_map(static fn (array $entry): string => QrCode::dataUri($entry['url']), $this->sheet),
        ]);
    }

    private function inviteAll(TenantInvitations $invitations, InvitationChannel $channel): void
    {
        [$this->notice, $this->problem] = [null, null];
        $this->sheet = [];
        $user = $this->user();
        $sent = 0;
        $skipped = 0;
        $refused = null;

        $tenants = $this->tenants();
        $invited = $this->invited($tenants);

        foreach ($tenants as $tenant) {
            if (isset($invited[$tenant->id]) || ($channel === InvitationChannel::Email && $tenant->email === null)) {
                continue; // Already invited (send again from their page), or no address to email.
            }

            if ($sent >= self::BATCH) {
                $skipped++;

                continue;
            }

            $this->authorize('managePortalAccess', $tenant);

            try {
                $link = $invitations->invite($user, $tenant, $channel);
            } catch (PortalAccessRefused $refusal) {
                $refused = $refusal->getMessage();
                $skipped++;

                continue;
            }

            $sent++;

            if ($channel === InvitationChannel::Link) {
                $this->sheet[] = ['name' => $tenant->fullName(), 'phone' => $tenant->phone, 'url' => $link->url];
            }
        }

        $this->notice = match ($channel) {
            InvitationChannel::Email => trans_choice('{0} Nobody was emailed.|{1} :count tenant was invited by email.|[2,*] :count tenants were invited by email.', $sent),
            InvitationChannel::WhatsApp => trans_choice('{0} Nobody was sent a WhatsApp message.|{1} :count tenant was invited by WhatsApp.|[2,*] :count tenants were invited by WhatsApp.', $sent),
            InvitationChannel::Link => trans_choice('{0} No invitation links were made.|{1} Print this sheet and hand the tenant their QR code. Each link works once, for :days days.|[2,*] Print this sheet and hand each tenant their QR code. Each link works once, for :days days.', $sent, ['days' => TenantInvitations::VALID_DAYS]),
        };

        if ($skipped > 0) {
            $this->problem = trans_choice('{1} :count tenant was not invited yet: :reason|[2,*] :count tenants were not invited yet: :reason', $skipped, [
                'reason' => $refused ?? __('at most :max at a time. Invite the others in a moment.', ['max' => self::BATCH]),
            ]);
        }
    }

    /**
     * The import's tenants who could use an invitation: not archived, no
     * portal account yet, by name.
     *
     * @return Collection<int, TenantProfile>
     */
    private function tenants(): Collection
    {
        $leases = app()->bound(ImportedLeases::class) ? app(ImportedLeases::class)->batchTenantIdsQuery($this->batchId) : null;

        return TenantProfile::query()
            ->where(fn (Builder $query) => $query->where('import_batch_id', $this->batchId)
                ->when($leases !== null, fn (Builder $query) => $query->orWhereIn('id', $leases)))
            ->whereNull('user_id')
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get();
    }

    /**
     * The tenants among these with an open invitation (as
     * TenantInvitations::open() reads it), in one query.
     *
     * @param  Collection<int, TenantProfile>  $tenants
     * @return array<int, true>
     */
    private function invited(Collection $tenants): array
    {
        if ($tenants->isEmpty()) {
            return [];
        }

        $ids = Invitation::query()
            ->where('kind', InvitationKind::Tenant)
            ->whereIn('tenant_profile_id', $tenants->modelKeys())
            ->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Expired])
            ->pluck('tenant_profile_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
