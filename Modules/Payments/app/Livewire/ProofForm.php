<?php

declare(strict_types=1);

namespace Modules\Payments\Livewire;

use App\Banking\BankCatalogue;
use App\Media\UploadValidator;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Payments\PaymentDates;
use Modules\Payments\Proofs\ProofSubmissions;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Upload a proof of payment for a tenant, /o/{organization}/payments/proofs/create
 * (optionally `?tenant={id}` or `?bill={id}`; P03-09): what the tenant sent
 * the agency (a WhatsApp screenshot, a teller slip) — amount, date paid,
 * sender, bank, narration and the file. It records a bank transfer waiting
 * for confirmation (source `proof_upload`) with the proof attached; the
 * same file uploaded before is flagged on the payment page. Tenants submit
 * their own from the portal (Contracts\PaymentProofs, P03-11b).
 *
 * PaymentProofPolicy::create (`payments.confirm`), checked again by
 * Proofs\ProofSubmissions; never while staff impersonate. The file is
 * checked by App\Media\UploadValidator here and again before it is stored.
 */
#[Layout('layouts::app')]
#[Title('Upload a proof of payment')]
final class ProofForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'tenant', except: '')]
    public string $tenantId = '';

    #[Url(as: 'bill', except: '')]
    public string $billId = '';

    /** Naira as typed. */
    public string $amount = '';

    public string $paidOn = '';

    public string $senderName = '';

    public string $bank = '';

    public string $reference = '';

    public string $note = '';

    /** The proof file chosen (Livewire's temporary upload). */
    public mixed $file = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, BillLedger $ledger, TenantDirectory $tenants): void
    {
        $this->organizationId = $context->id();
        $this->authorize('create', PaymentProof::class);
        $this->paidOn = PaymentDates::today()->toDateString();

        // ?bill= (a bill's page or the payment form): its tenant too, when the member sees both.
        $bill = ctype_digit($this->billId) ? $ledger->balance((int) $this->billId) : null;

        if ($bill !== null && $this->billOffered($bill) && $tenants->narrowingAllows($this->user(), $bill->tenantProfileId)) {
            $this->tenantId = (string) $bill->tenantProfileId;
        } else {
            $this->billId = '';
        }

        if ($this->tenantId !== '' && (! ctype_digit($this->tenantId) || ! array_key_exists((int) $this->tenantId, $tenants->activeOptions($this->user())))) {
            $this->tenantId = '';
            $this->billId = '';
        }
    }

    /** Another tenant chosen: their bills, not the last one's. */
    public function updatedTenantId(): void
    {
        $this->billId = '';
    }

    #[BlockedWhileImpersonating]
    public function save(ProofSubmissions $proofs, UploadValidator $validator): void
    {
        $this->authorize('create', PaymentProof::class);
        $this->validate(
            ['file' => ['required', 'file', $validator->rule(PaymentProof::COLLECTION)]],
            ['file.required' => __('Choose the proof: a screenshot, a photo or a PDF.')],
        );
        assert($this->file instanceof UploadedFile);

        $proof = $proofs->submitForMember($this->user(), [
            'tenantId' => $this->tenantId,
            'billId' => $this->billId,
            'amount' => $this->amount,
            'paidOn' => $this->paidOn,
            'senderName' => $this->senderName,
            'bank' => $this->bank,
            'reference' => $this->reference,
            'note' => $this->note,
        ], $this->file);

        session()->flash('payment-status', $proof->duplicate_of_proof_id === null
            ? __('The proof was saved and its payment waits for confirmation.')
            : __('The proof was saved and its payment waits for confirmation. The same file was uploaded before: check it before confirming.'));

        $this->redirectRoute('workspace.payments.show', ['organization' => $this->organizationSlug(), 'payment' => $proof->payment_id], navigate: true);
    }

    public function render(OrganizationContext $context, TenantDirectory $tenants, BillLedger $ledger, BankCatalogue $banks): View
    {
        $user = $this->user();
        $bills = ctype_digit($this->tenantId) && $tenants->narrowingAllows($user, (int) $this->tenantId)
            ? array_values(array_filter($ledger->openBills((int) $this->tenantId), $this->billOffered(...)))
            : [];

        return view('payments::livewire.proof-form', [
            'organization' => $context->get(),
            'tenants' => $tenants->activeOptions($user),
            'bills' => $bills,
            'banks' => array_values($banks->all()),
        ]);
    }

    /** A bill the member may record a payment for: not void, in their buildings. */
    private function billOffered(BillBalance $bill): bool
    {
        if ($bill->isVoid()) {
            return false;
        }

        $access = app(BuildingAccess::class);

        return $bill->propertyId === null ? $access->limitedTo($this->user()) === null : $access->allows($this->user(), $bill->propertyId);
    }

    private function organizationSlug(): string
    {
        return (string) app(OrganizationContext::class)->get()->slug;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
