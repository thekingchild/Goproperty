<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Banking\BankCatalogue;
use App\Media\UploadValidator;
use App\Models\User;
use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Tenants\Portal\PortalPayments;
use Modules\Tenants\Portal\PortalProofPage;

/**
 * Send a proof of payment for one of my bills, /portal/bills/{bill}/proof
 * (P03-11b; ONBOARDING_COMPLIANCE.md § 4.1 "Proof upload stays as the
 * tenant-side fallback"): the amount, the date paid, the sender's name and
 * bank, an optional narration and note, and the file (a screenshot, a photo
 * of a teller slip or a PDF). It records a bank transfer waiting for the
 * agency's confirmation, through Payments' Contracts\PaymentProofs (P03-09):
 * the file is checked from its bytes, the same file sent before is flagged
 * for the reviewer (never told here), and at most 5 proofs a bill an hour
 * and 20 a tenant a day are taken — refusals show as plain messages.
 *
 * The bill is looked up among the user's own on every request
 * (Portal\PortalPayments), so another tenant's or organization's bill
 * answers 404. It keeps working while the agency is suspended (the portal
 * is read-only there but still takes proofs, ONBOARDING_COMPLIANCE.md § 3),
 * and is refused while GoProperty staff impersonate the user (a money
 * action, PLATFORM_CONSOLE.md § 6).
 */
#[Layout('layouts::app')]
#[Title('Send a proof of payment')]
final class PortalProofForm extends Component
{
    use WithFileUploads;

    /** Tries to send a proof (valid or not) a user may make a minute, before the per-bill and per-tenant limits. */
    public const int ATTEMPTS_PER_MINUTE = 10;

    #[Locked]
    public int $billId;

    /** Naira as typed. */
    public string $amount = '';

    public string $paidOn = '';

    public string $senderName = '';

    public string $bank = '';

    public string $reference = '';

    public string $note = '';

    /** The proof file chosen (Livewire's temporary upload). */
    public mixed $file = null;

    public function mount(PortalPayments $payments, int $bill): void
    {
        $this->billId = $bill;
        $page = $this->page($payments);

        $this->amount = str_replace('₦', '', $page->bill->outstanding->format());
        $this->paidOn = now('Africa/Lagos')->toDateString();
        $this->senderName = $this->user()->name;
    }

    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function save(PortalPayments $payments, UploadValidator $validator): void
    {
        $user = $this->user();
        $key = 'portal-proof-attempts:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages(['proof' => __('Too many tries. Try again in :seconds seconds.', ['seconds' => max(1, RateLimiter::availableIn($key))])]);
        }

        RateLimiter::hit($key, 60);

        $this->validate(
            ['file' => ['required', 'file', $validator->rule(PaymentProofs::COLLECTION)]],
            ['file.required' => __('Choose the proof: a screenshot, a photo or a PDF.')],
            ['file' => __('proof')],
        );
        assert($this->file instanceof UploadedFile);

        try {
            $proof = $payments->submitProof($user, $this->billId, [
                'amount' => $this->amount,
                'paidOn' => $this->paidOn,
                'senderName' => $this->senderName,
                'bank' => $this->bank,
                'reference' => $this->reference,
                'note' => $this->note,
            ], $this->file);
        } catch (AuthorizationException $refused) {
            // Paid or cancelled meanwhile: say so on the form. Not theirs: 404.
            if ($refused->status() === 404) {
                throw $refused;
            }

            throw ValidationException::withMessages(['proof' => $refused->getMessage()]);
        }

        abort_if($proof === null, 404);

        session()->flash('portal-status', __('Proof sent. The agency will check it and confirm your payment; you will get a receipt.'));

        $this->redirectRoute('portal.bills.show', ['bill' => $this->billId], navigate: true);
    }

    public function render(PortalPayments $payments, BankCatalogue $banks): View
    {
        return view('tenants::livewire.portal.proof-form', [
            'page' => $this->page($payments),
            'banks' => array_values($banks->all()),
        ]);
    }

    private function page(PortalPayments $payments): PortalProofPage
    {
        $page = $payments->proofPage($this->user(), $this->billId);
        abort_if($page === null, 404);

        return $page;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
