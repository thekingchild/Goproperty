<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire;

use App\Models\User;
use App\Settings\SettingsStore;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tickets\Tickets\TicketSettings;

/**
 * Settings → Maintenance tickets, /o/{organization}/settings/operations/tickets
 * (P04-01; docs/plans/P04-subtasks.md defaults 1 and 2): how many days
 * after a resolution a tenant may still reopen a ticket
 * (`tickets.reopen_days`, 1–90, default 14) and after how many days a
 * resolved ticket nobody answered closes itself (`tickets.auto_close_days`,
 * 1–30, default 7). Org-owners and administrators see it (`viewSettings`,
 * read-only while suspended) and save it (`updateProfile`, like the rest
 * of how the organization works); the settings store checks the values
 * against their definitions and audits every change.
 */
#[Layout('layouts::app')]
#[Title('Maintenance tickets')]
final class TicketSettingsPage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $reopenDays = '';

    public string $autoCloseDays = '';

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

    public function mount(OrganizationContext $context, TicketSettings $settings): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewSettings', $organization);
        $this->reopenDays = (string) $settings->reopenDays();
        $this->autoCloseDays = (string) $settings->autoCloseDays();
    }

    public function save(OrganizationContext $context, SettingsStore $settings): void
    {
        $organization = $context->get();
        $this->authorize('updateProfile', $organization);
        $this->validate([
            'reopenDays' => ['required', 'integer', 'between:1,'.TicketSettings::MAX_REOPEN_DAYS],
            'autoCloseDays' => ['required', 'integer', 'between:1,'.TicketSettings::MAX_AUTO_CLOSE_DAYS],
        ], [], ['reopenDays' => __('reopen window'), 'autoCloseDays' => __('days before closing')]);

        $settings->set(TicketSettings::REOPEN_DAYS, (int) $this->reopenDays, $organization);
        $settings->set(TicketSettings::AUTO_CLOSE_DAYS, (int) $this->autoCloseDays, $organization);
        $this->status = __('Saved. The new numbers apply to every ticket from now on.');
    }

    public function render(OrganizationContext $context): View
    {
        return view('tickets::livewire.ticket-settings-page', [
            'canEdit' => $this->user()->can('updateProfile', $context->get()),
            'maxReopenDays' => TicketSettings::MAX_REOPEN_DAYS,
            'maxAutoCloseDays' => TicketSettings::MAX_AUTO_CLOSE_DAYS,
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
