<?php

declare(strict_types=1);

namespace Modules\LateFees\Livewire;

use App\Models\User;
use App\Settings\SettingsStore;
use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\LateFees\Charges\LateFeeSettings;

/**
 * Settings → Late fees, /o/{organization}/settings/payments/late-fees
 * (PL-02, docs/plans/late-fees-2026-11-11.md): switch late fees on or off,
 * the percentage of what is unpaid, the grace days after the due date and
 * an optional cap. Seen with `viewSettings`, saved by owners and
 * administrators (`updateProfile`), never while GoProperty staff
 * impersonate (a money setting); audited by the settings store.
 */
#[Layout('layouts::app')]
#[Title('Late fees')]
final class LateFeeSettingsPage extends Component
{
    #[Locked]
    public int $organizationId;

    public bool $enabled = false;

    /** The percentage as typed, e.g. "5" or "2.5". */
    public string $rate = '';

    public string $graceDays = '';

    /** Naira, "" = no cap. */
    public string $cap = '';

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewSettings', $organization);
        $this->fill(self::form(LateFeeSettings::of($organization)));
    }

    #[BlockedWhileImpersonating]
    public function save(OrganizationContext $context, SettingsStore $settings): void
    {
        $organization = $context->get();
        $this->authorize('updateProfile', $organization);
        $this->status = null;

        $this->validate([
            'enabled' => ['boolean'],
            'rate' => ['required', 'numeric', 'min:0.01', 'max:'.(LateFeeSettings::MAX_RATE_BPS / 100), 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'graceDays' => ['required', 'integer', 'between:1,'.LateFeeSettings::MAX_GRACE_DAYS],
            'cap' => ['nullable', 'regex:/^[\d,]{1,15}$/'],
        ], ['rate.regex' => __('Type the percentage with at most two decimals, e.g. 5 or 2.5.')], [
            'rate' => __('percentage'),
            'graceDays' => __('grace days'),
            'cap' => __('cap'),
        ]);

        $cap = $this->cap === '' ? null : Money::naira(str_replace(',', '', $this->cap))->kobo;

        if ($cap !== null && ($cap < LateFeeSettings::MIN_CAP_KOBO || $cap > LateFeeSettings::MAX_CAP_KOBO)) {
            $this->addError('cap', __('The cap is between ₦1 and ₦100,000,000, or empty for no cap.'));

            return;
        }

        $values = [
            LateFeeSettings::ENABLED => $this->enabled,
            LateFeeSettings::RATE_BPS => (int) round((float) $this->rate * 100),
            LateFeeSettings::GRACE_DAYS => (int) $this->graceDays,
            LateFeeSettings::CAP_KOBO => $cap,
        ];
        $changed = false;

        foreach ($values as $key => $value) {
            if ($settings->get($key, $organization) !== $value) {
                // Audited by the settings store.
                $settings->set($key, $value, $organization);
                $changed = true;
            }
        }

        $now = LateFeeSettings::of($organization);
        $this->fill(self::form($now));
        $this->status = ! $changed ? __('Nothing changed.') : ($now->enabled
            ? __('Saved. From tomorrow, a rent bill still unpaid :days days after its due date gets a late fee of :rate of what is unpaid:cap.', [
                'days' => $now->graceDays,
                'rate' => $now->rateLabel(),
                'cap' => $now->cap !== null ? __(', at most :cap', ['cap' => $now->cap->format()]) : '',
            ])
            : __('Saved. Late fees are off: no new late fee is charged.'));
    }

    public function render(OrganizationContext $context): View
    {
        return view('latefees::livewire.late-fee-settings-page', [
            'canEdit' => $this->user()->can('updateProfile', $context->get()),
        ]);
    }

    /** @return array{enabled: bool, rate: string, graceDays: string, cap: string} */
    private static function form(LateFeeSettings $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            'rate' => rtrim(rtrim(number_format($settings->rateBps / 100, 2, '.', ''), '0'), '.'),
            'graceDays' => (string) $settings->graceDays,
            'cap' => $settings->cap !== null ? number_format(intdiv($settings->cap->kobo, 100)) : '',
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
