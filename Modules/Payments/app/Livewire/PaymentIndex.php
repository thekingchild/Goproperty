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
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Lists\PaymentList;
use Modules\Payments\Lists\PaymentRows;
use Modules\Payments\Models\Payment;
use Modules\Payments\Payments\PartPaymentCounts;

/**
 * Payments, /o/{organization}/payments (ARCHITECTURE.md § 6.4; P03-10a),
 * paginated on the server (25 a page), with a debounced search over the
 * tenant, the payer and the reference, and filters by status, method and
 * source, plus a tenant or a bill, all kept in the URL (§ 13). "To
 * confirm" is the confirmation queue: payments waiting for confirmation,
 * oldest paid first. The tenants' names of a page are read in one lookup
 * (Lists\PaymentRows, no N+1).
 *
 * Members with `payments.view` see it (PaymentPolicy::viewAny); an agent
 * assigned to buildings sees their tenants' payments only. The rows come
 * from Lists\PaymentList, which also exports them (P02-07). Runs inside
 * the `organization` middleware, replayed for Livewire updates.
 */
#[Layout('layouts::app')]
#[Title('Payments')]
final class PaymentIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: '')]
    public string $source = '';

    /** A tenant id, or empty. */
    #[Url(except: '')]
    public string $tenant = '';

    /** A bill id, or empty. */
    #[Url(except: '')]
    public string $bill = '';

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
        $this->authorize('viewAny', Payment::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'method', 'source', 'tenant', 'bill'], true)) {
            $this->resetPage();
        }
    }

    /** The confirmation queue (status: waiting for confirmation), or every payment. */
    public function showQueue(bool $queue = true): void
    {
        $this->status = $queue ? PaymentStatus::PendingConfirmation->value : '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'method', 'source', 'tenant', 'bill');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, PaymentList $list, PaymentRows $rows, PartPaymentCounts $partPayments): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['payments']);
        $payments = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('payments::livewire.payment-index', [
            'organization' => $context->get(),
            'payments' => $payments,
            'rows' => $rows->rows($payments->getCollection()),
            // Waiting payments that would take a rent bill past its lease's number of payments (P03-17).
            'overPartPayments' => $payments->getCollection()
                ->filter(static fn (Payment $payment): bool => $payment->status->isPending() && $payment->bill_id !== null)
                ->filter(static function (Payment $payment) use ($partPayments): bool {
                    $allowance = $partPayments->forBill((int) $payment->bill_id, leaving: $payment->id);

                    return $allowance !== null && $allowance->made + 1 > $allowance->limit;
                })
                ->map(static fn (Payment $payment): int => $payment->id)
                ->values()
                ->all(),
            'filtered' => $filters !== [],
            'waiting' => $list->query($user, ['status' => PaymentStatus::PendingConfirmation->value])->count(),
            'statuses' => PaymentStatus::cases(),
            'methods' => PaymentMethod::cases(),
            'sources' => PaymentSource::cases(),
            'canRecord' => $user->can('create', Payment::class),
        ]);
    }

    /** The list on the page, with its filters (PaymentList; the Export button). */
    protected function listExports(): array
    {
        return ['payments' => [
            'search' => $this->search,
            'status' => $this->status,
            'method' => $this->method,
            'source' => $this->source,
            'tenant' => $this->tenant,
            'bill' => $this->bill,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
