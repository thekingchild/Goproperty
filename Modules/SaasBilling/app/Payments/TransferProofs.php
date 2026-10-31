<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Payments;

use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\SaasBilling\Contracts\BankTransfers;
use Modules\SaasBilling\Contracts\TransferAccount;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use OverflowException;

/**
 * Bank transfers into GoProperty's own account (P06-04; BILLING_PAYMENTS.md
 * § 5 steps 1–2; docs/plans/P06-subtasks.md defaults 31–37): the account
 * (settings `billing.transfer_*`, set in the console by `finance-admin`)
 * and the owners' proofs.
 *
 * A proof is, in one transaction under the invoice's lock: a
 * `platform_payments` row (method `bank_transfer`, status `pending`, for
 * the invoice's total, with what the owner says about the transfer), its
 * file in the `platform_payment_proofs` collection (type sniffed, at most
 * 10 MB, evidence: never deleted) and an audit entry. **Nothing else
 * changes**: the invoice stays open and the subscription as it was until
 * GoProperty's staff confirm the transfer (Payments\TransferReviews).
 *
 * Refused: an invoice that is not an open subscription invoice of the
 * organization; a second proof while one for the invoice is still waiting;
 * more than BankTransfers::PER_ORGANIZATION_PER_DAY proofs a day; while
 * the account is not set. The same file sent before is kept and flagged
 * for staff (`proof_sha256`), never refused: one transfer may honestly be
 * shown twice.
 */
final readonly class TransferProofs implements BankTransfers
{
    public const string ACCOUNT_BANK = 'billing.transfer_bank';

    public const string ACCOUNT_NAME = 'billing.transfer_account_name';

    public const string ACCOUNT_NUMBER = 'billing.transfer_account_number';

    public function __construct(
        private SettingsStore $settings,
        private UploadValidator $validator,
        private MediaUploads $uploads,
        private OrganizationContext $context,
    ) {}

    public function account(): ?TransferAccount
    {
        $bank = $this->settings->get(self::ACCOUNT_BANK);
        $name = $this->settings->get(self::ACCOUNT_NAME);
        $number = $this->settings->get(self::ACCOUNT_NUMBER);

        if (! is_string($bank) || trim($bank) === '' || ! is_string($name) || trim($name) === '' || ! is_string($number) || trim($number) === '') {
            return null;
        }

        return new TransferAccount(trim($bank), trim($name), trim($number));
    }

    public function submit(User $owner, string $invoiceNumber, array $input, UploadedFile $file): void
    {
        if (! $this->account() instanceof TransferAccount) {
            throw ValidationException::withMessages(['invoice' => __('Paying by bank transfer is not available yet. Contact GoProperty.')]);
        }

        $values = $this->validated($input);
        $sha256 = $this->checkedFile($file);
        $limit = 'platform-transfer-proofs:'.$this->context->id();

        if (RateLimiter::tooManyAttempts($limit, self::PER_ORGANIZATION_PER_DAY)) {
            throw ValidationException::withMessages(['file' => __('Too many proofs were sent today. Try again in :hours hours, or contact GoProperty.', [
                'hours' => max(1, (int) ceil(RateLimiter::availableIn($limit) / 3600)),
            ])]);
        }

        DB::transaction(function () use ($owner, $invoiceNumber, $values, $sha256, $file): void {
            $invoice = PlatformInvoice::query()
                ->where('kind', InvoiceKind::Subscription)
                ->where('number', $invoiceNumber)
                ->lockForUpdate()
                ->first();

            if (! $invoice instanceof PlatformInvoice) {
                throw ValidationException::withMessages(['invoice' => __('That invoice was not found.')]);
            }

            if ($invoice->status !== InvoiceStatus::Open) {
                throw ValidationException::withMessages(['invoice' => __('Invoice :number is already paid.', ['number' => $invoice->number])]);
            }

            $waiting = PlatformPayment::query()
                ->where('platform_invoice_id', $invoice->id)
                ->where('method', PlatformPayment::BANK_TRANSFER)
                ->where('status', PlatformPaymentStatus::Pending)
                ->exists();

            if ($waiting) {
                throw ValidationException::withMessages(['invoice' => __('GoProperty has your proof for invoice :number already and is checking it. You will hear back once it is reviewed.', ['number' => $invoice->number])]);
            }

            $sentBefore = PlatformPayment::query()->where('proof_sha256', $sha256)->exists();

            $payment = new PlatformPayment;
            $payment->forceFill([
                'platform_invoice_id' => $invoice->id,
                'method' => PlatformPayment::BANK_TRANSFER,
                'amount_kobo' => $invoice->total_kobo,
                'currency' => 'NGN',
                'status' => PlatformPaymentStatus::Pending,
                'initiated_by_user_id' => $owner->id,
                'proof_sha256' => $sha256,
                'stated_amount_kobo' => $values['amount'],
                'transferred_on' => $values['transferredOn'],
                'sender_name' => $values['senderName'],
                'sender_bank' => $values['senderBank'],
                'transfer_narration' => $values['narration'],
            ])->save();

            $media = $this->uploads->add($payment, PlatformPayment::PROOF_COLLECTION, $file, null, ['platform_payment_id' => $payment->id]);
            $payment->bank_transfer_proof_media_id = $media->id;
            $payment->save();

            activity(SubscriptionMetering::LOG)
                ->event('platform_payment.transfer_submitted')
                ->performedOn($payment)
                ->causedBy($owner)
                ->withProperties([
                    'organization_id' => $invoice->organization_id,
                    'invoice' => $invoice->number,
                    'total_kobo' => $invoice->total_kobo->kobo,
                    'stated_amount_kobo' => $values['amount']->kobo,
                    'transferred_on' => $values['transferredOn']->toDateString(),
                    'file_sha256' => $sha256,
                    'same_file_as_before' => $sentBefore,
                ])
                ->log("Proof of a bank transfer sent for invoice {$invoice->number}");
        }, 3);

        RateLimiter::hit($limit, 86400);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{amount: Money, transferredOn: CarbonImmutable, senderName: string, senderBank: string|null, narration: string|null}
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $values = [];

        foreach (['amount', 'transferredOn', 'senderName', 'senderBank', 'narration'] as $key) {
            $value = $input[$key] ?? null;
            $values[$key] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        }

        $today = CarbonImmutable::now('Africa/Lagos')->toDateString();
        $earliest = CarbonImmutable::now('Africa/Lagos')->subDays(90)->toDateString();
        $plain = 'regex:/^[^<>]*$/';

        Validator::make($values, [
            'amount' => ['required', 'string', 'max:20'],
            'transferredOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:'.$earliest],
            'senderName' => ['required', 'string', 'min:2', 'max:120', $plain],
            'senderBank' => ['nullable', 'string', 'max:80', $plain],
            'narration' => ['nullable', 'string', 'max:100', $plain],
        ], [
            'amount.required' => __('Say how much you sent.'),
            'transferredOn.required' => __('Say when you sent it.'),
            'transferredOn.before_or_equal' => __('The day of the transfer cannot be in the future.'),
            'transferredOn.after_or_equal' => __('The transfer must be from the last 90 days.'),
            'senderName.required' => __('Give the name on the account you sent it from.'),
        ], [
            'amount' => __('amount'), 'transferredOn' => __('day of the transfer'), 'senderName' => __('sender\'s name'),
            'senderBank' => __('bank'), 'narration' => __('narration'),
        ])->validate();

        try {
            $amount = Money::naira((string) $values['amount']);
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['amount' => __('Give the amount in naira, for example 12,900.')]);
        }

        if (! $amount->isPositive() || $amount->greaterThan(Money::naira(100_000_000))) {
            throw ValidationException::withMessages(['amount' => __('Give the amount in naira, for example 12,900.')]);
        }

        return [
            'amount' => $amount,
            'transferredOn' => CarbonImmutable::parse((string) $values['transferredOn'], 'Africa/Lagos'),
            'senderName' => (string) $values['senderName'],
            'senderBank' => $values['senderBank'],
            'narration' => $values['narration'],
        ];
    }

    /** @throws ValidationException */
    private function checkedFile(UploadedFile $file): string
    {
        try {
            return $this->validator->check(PlatformPayment::PROOF_COLLECTION, $file)->sha256;
        } catch (UploadRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }
    }
}
