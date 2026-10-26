<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use App\Enums\OrganizationPermission;
use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A stand-in workspace component for the impersonation tests: an ordinary
 * write, a payment confirmation behind `payments.confirm`, a method marked
 * #[BlockedWhileImpersonating] (e.g. rotating Paystack keys), an export
 * and paging.
 */
final class SupportProbe extends Component
{
    use WithPagination;

    public string $search = '';

    public function save(): void
    {
        Cache::store('array')->increment('support.saves');
    }

    public function confirmPayment(): void
    {
        $this->authorize(OrganizationPermission::ConfirmPayments->value);
        Cache::store('array')->increment('support.confirms');
    }

    #[BlockedWhileImpersonating]
    public function rotatePaystackKeys(): void
    {
        Cache::store('array')->increment('support.keys');
    }

    #[AllowedWhileSuspended]
    public function export(): void
    {
        Cache::store('array')->increment('support.exports');
    }

    public function render(): string
    {
        $slug = app(OrganizationContext::class)->get()->slug;

        return <<<HTML
            <div>Support probe {$slug}</div>
            HTML;
    }
}
