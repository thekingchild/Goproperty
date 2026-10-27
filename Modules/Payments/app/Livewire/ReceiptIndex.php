<?php

declare(strict_types=1);

namespace Modules\Payments\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Payments\Enums\ReceiptStatus;
use Modules\Payments\Lists\ReceiptList;
use Modules\Payments\Lists\ReceiptRows;
use Modules\Payments\Models\Receipt;

/**
 * Receipts, /o/{organization}/receipts (P03-10b; BRANDING_DOCUMENTS.md
 * § 3): every receipt issued for a confirmed payment, newest first, 25 a
 * page on the server, with a debounced search over the number and the
 * tenant, and filters by status — "Void requested" is the approvers' queue
 * — and tenant, all kept in the URL. Names come from the receipts
 * themselves (Lists\ReceiptRows, no N+1).
 *
 * Members with `payments.view` see it (ReceiptPolicy::viewAny); an agent
 * assigned to buildings sees their tenants' receipts only. The rows come
 * from Lists\ReceiptList, which also exports them (P02-07).
 */
#[Layout('layouts::app')]
#[Title('Receipts')]
final class ReceiptIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    /** A tenant id, or empty. */
    #[Url(except: '')]
    public string $tenant = '';

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
        $this->organizationId = $context->id();
        $this->authorize('viewAny', Receipt::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'tenant'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'tenant');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, ReceiptList $list, ReceiptRows $rows): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['receipts']);
        $receipts = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('payments::livewire.receipt-index', [
            'organization' => $context->get(),
            'receipts' => $receipts,
            'rows' => $rows->rows($receipts->getCollection()),
            'filtered' => $filters !== [],
            'waiting' => $list->query($user, ['status' => ReceiptStatus::VoidRequested->value])->count(),
            'statuses' => ReceiptStatus::cases(),
        ]);
    }

    /** The list on the page, with its filters (ReceiptList; the Export button). */
    protected function listExports(): array
    {
        return ['receipts' => [
            'search' => $this->search,
            'status' => $this->status,
            'tenant' => $this->tenant,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
