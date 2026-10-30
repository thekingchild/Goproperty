<?php

declare(strict_types=1);

namespace Modules\WhatsApp\OptIns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Contracts\TenantSummary;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Enums\OptInSource;
use Modules\WhatsApp\Models\WhatsAppOptIn;

/**
 * Tenants' consent to the agency's WhatsApp messages (Meta policy;
 * ARCHITECTURE.md § 6.7; DATABASE_SCHEMA.md § 7 `whatsapp_opt_ins`), in the
 * current organization: the only writer of `whatsapp_opt_ins`.
 *
 * - **The number:** a tenant's WhatsApp number is their portal account's
 *   number when they have an account, else the tenant record's. An opt-in
 *   counts only while it is still that number, so a changed number needs
 *   consent again.
 * - **Given** in the portal by the tenant (their account's number, which
 *   must be verified), or recorded by an agency member who asked the tenant
 *   in person. Opting in again at another number closes the old opt-in.
 * - **Taken back** at once: by the tenant in the portal or recorded by the
 *   agency; from then on no WhatsApp goes to that number for any message,
 *   and reminders use their next channel.
 *
 * Every change is audited under log `whatsapp` (masked number, the
 * tenant's id, how). Callers check the policy; this class checks nothing
 * about who asks.
 */
final readonly class WhatsAppOptIns
{
    public function __construct(private TenantDirectory $tenants) {}

    /** The tenant's newest opt-in, open or closed, or null. */
    public function latest(int $tenantId): ?WhatsAppOptIn
    {
        return WhatsAppOptIn::query()->where('tenant_profile_id', $tenantId)->orderByDesc('id')->first();
    }

    /** The tenant's WhatsApp number now: their portal account's, else the record's (E.164), or null. */
    public function numberOf(TenantSummary $tenant): ?string
    {
        $account = $tenant->userId !== null ? User::query()->find($tenant->userId) : null;
        $phone = $account instanceof User ? (string) $account->phone : $tenant->phone;
        $phone = trim($phone);

        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1 ? $phone : null;
    }

    /**
     * The opted-in number of each of these tenants that is still theirs.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, string>
     */
    public function optedIn(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $open = WhatsAppOptIn::query()->whereIn('tenant_profile_id', $tenantIds)->whereNull('opted_out_at')->orderBy('id')->get()->keyBy('tenant_profile_id');

        if ($open->isEmpty()) {
            return [];
        }

        $numbers = [];

        foreach ($this->tenants->findMany(array_values(array_map('intval', $open->keys()->all()))) as $id => $tenant) {
            $optIn = $open->get($id);
            $number = $tenant->archived ? null : $this->numberOf($tenant);

            if ($optIn instanceof WhatsAppOptIn && $number !== null && $number === $optIn->phone) {
                $numbers[$id] = $number;
            }
        }

        return $numbers;
    }

    /** Whether this number's newest opt-in here was taken back: nothing goes to it by WhatsApp. */
    public function optedOut(string $phone): bool
    {
        $latest = WhatsAppOptIn::query()->where('phone', $phone)->orderByDesc('id')->first();

        return $latest instanceof WhatsAppOptIn && ! $latest->open() && $latest->opt_out_source !== OptInSource::Replaced;
    }

    /**
     * Records the tenant's consent at their number now.
     *
     * @throws OptInRefused
     */
    public function optIn(int $tenantId, OptInSource $source, ?User $by): WhatsAppOptIn
    {
        return DB::transaction(function () use ($tenantId, $source, $by): WhatsAppOptIn {
            $tenant = $this->tenants->lockActive($tenantId) ?? throw new OptInRefused(__('This tenant cannot be found, or is archived.'));
            $number = $this->numberOf($tenant) ?? throw new OptInRefused(__('Add the tenant\'s phone number first.'));
            $open = WhatsAppOptIn::query()->where('tenant_profile_id', $tenantId)->whereNull('opted_out_at')->lockForUpdate()->orderByDesc('id')->first();

            if ($open instanceof WhatsAppOptIn && $open->phone === $number) {
                return $open;
            }

            if ($open instanceof WhatsAppOptIn) {
                $this->close($open, OptInSource::Replaced, $by);
            }

            $optIn = new WhatsAppOptIn;
            $optIn->forceFill([
                'tenant_profile_id' => $tenantId,
                'phone' => $number,
                'opted_in_at' => now(),
                'source' => $source,
                'recorded_by_user_id' => $by?->id,
            ])->save();

            $this->audit('whatsapp.opted_in', $optIn, $by, 'Tenant agreed to WhatsApp messages', $source);

            return $optIn;
        }, 3);
    }

    /** Takes the tenant's consent back at once; false when there was none open. */
    public function optOut(int $tenantId, OptInSource $source, ?User $by): bool
    {
        return DB::transaction(function () use ($tenantId, $source, $by): bool {
            $open = WhatsAppOptIn::query()->where('tenant_profile_id', $tenantId)->whereNull('opted_out_at')->lockForUpdate()->get();

            foreach ($open as $optIn) {
                $this->close($optIn, $source, $by);
                $this->audit('whatsapp.opted_out', $optIn, $by, 'Tenant stopped WhatsApp messages', $source);
            }

            return $open->isNotEmpty();
        }, 3);
    }

    private function close(WhatsAppOptIn $optIn, OptInSource $source, ?User $by): void
    {
        $optIn->forceFill(['opted_out_at' => now(), 'opt_out_source' => $source, 'opted_out_by_user_id' => $by?->id])->save();
    }

    private function audit(string $event, WhatsAppOptIn $optIn, ?User $by, string $description, OptInSource $source): void
    {
        $activity = activity(WhatsAppConnections::AUDIT_LOG)
            ->event($event)
            ->performedOn($optIn)
            ->withProperties([
                'tenant_profile_id' => $optIn->tenant_profile_id,
                'phone' => substr($optIn->phone, 0, 4).' ••• '.substr($optIn->phone, -4),
                'source' => $source->value,
            ]);

        if ($by !== null) {
            $activity->causedBy($by);
        }

        $activity->log($description);
    }
}
