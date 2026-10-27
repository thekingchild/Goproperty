<?php

declare(strict_types=1);

namespace Modules\Payments\Proofs;

use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Contracts\BillLedger;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Contracts\SubmittedProof;
use Modules\Payments\Enums\ProofSubmitter;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Payments\NairaInput;
use Modules\Payments\Payments\PaymentDates;
use Modules\Payments\Payments\PaymentService;

/**
 * Proofs of payment (P03-09; ONBOARDING_COMPLIANCE.md § 4.1, ARCHITECTURE.md
 * § 6.4, § 9, § 10): the only writer of `payment_proofs`.
 *
 * - submitForBill(): the tenant, from the portal (Contracts\PaymentProofs,
 *   for P03-11b), for one of their own bills — proven by their portal
 *   account (PaymentProofPolicy::submitAsTenant). Kept working while the
 *   organization is suspended (ONBOARDING_COMPLIANCE.md § 3).
 * - submitForMember(): a member uploads a proof the tenant sent them, for
 *   a tenant they see and optionally one of their bills
 *   (PaymentProofPolicy::create, `payments.confirm`).
 * - archive(): "remove" a proof: it is evidence (AGENT.md § 3 rule 7), so
 *   it is archived with a reason and its file is kept.
 *
 * A submission, in one transaction: the payment the proof shows is recorded
 * waiting for confirmation (PaymentService::recordProof: source
 * `proof_upload`, the tenant told it was received), the proof row, and the
 * file through App\Media\MediaUploads into the proof's `payment_proofs`
 * collection (type sniffed, size capped, never SVG; UploadValidator checks
 * it before anything is written). The reviewer confirms or rejects the
 * payment in the confirmation queue.
 *
 * Same file twice: the SHA-256 of the file's bytes is compared within the
 * organization. The first proof with a hash holds it in
 * `first_upload_sha256` (unique per organization); a later one fails that
 * key, waits for the first to commit if they race, and is flagged with
 * `duplicate_of_proof_id`. A duplicate is shown to the reviewer, never
 * refused automatically: a tenant may re-send the same screenshot by
 * mistake, or the same transfer may honestly cover two bills; the reviewer
 * decides (rejects the copy with a reason, or confirms it).
 *
 * Rate limits (ARCHITECTURE.md § 10, "proof uploads"): at most
 * PaymentProofs::PER_BILL_PER_HOUR proofs for one bill an hour and
 * PER_TENANT_PER_DAY for one tenant a day, whoever submits them; a stored
 * proof counts.
 */
final readonly class ProofSubmissions implements PaymentProofs
{
    public function __construct(
        private PaymentService $payments,
        private UploadValidator $validator,
        private MediaUploads $uploads,
        private BillLedger $ledger,
        private OrganizationContext $context,
    ) {}

    public function maySubmit(User $tenant, int $billId): bool
    {
        return Gate::forUser($tenant)->allows('submitAsTenant', [PaymentProof::class, $billId]);
    }

    public function submitForBill(User $tenant, int $billId, array $input, UploadedFile $file): SubmittedProof
    {
        Gate::forUser($tenant)->authorize('submitAsTenant', [PaymentProof::class, $billId]);
        $bill = $this->ledger->balance($billId);
        abort_if($bill === null, 404);

        $proof = $this->submit(null, $tenant, ProofSubmitter::Tenant, $bill->tenantProfileId, $bill->id, $input, $file);

        return new SubmittedProof($proof->id, $proof->payment_id, $proof->duplicate_of_proof_id);
    }

    /**
     * A member uploads a proof the tenant sent them: $input as
     * submitForBill() plus `tenantId` and an optional `billId`.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function submitForMember(User $member, array $input, UploadedFile $file): PaymentProof
    {
        Gate::forUser($member)->authorize('create', PaymentProof::class);

        $ids = [
            'tenantId' => self::text($input['tenantId'] ?? null),
            'billId' => self::text($input['billId'] ?? null),
        ];
        Validator::make($ids, [
            'tenantId' => ['required', 'integer', 'min:1'],
            'billId' => ['nullable', 'integer', 'min:1'],
        ], ['tenantId.required' => __('Choose a tenant.')], ['tenantId' => 'tenant', 'billId' => 'bill'])->validate();

        return $this->submit($member, $member, ProofSubmitter::Member, (int) $ids['tenantId'], $ids['billId'] === null ? null : (int) $ids['billId'], $input, $file);
    }

    /**
     * "Remove" a proof: archived with the reason, the file kept (evidence).
     * The payment is not changed: reject it if it should not count.
     *
     * @throws ValidationException
     */
    public function archive(User $member, PaymentProof $proof, string $reason): PaymentProof
    {
        Gate::forUser($member)->authorize('archive', $proof);
        $reason = (string) self::text($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => __('Say why the proof is archived.'),
        ])->validate();

        return DB::transaction(function () use ($member, $proof, $reason): PaymentProof {
            $proof = PaymentProof::query()->whereKey($proof->id)->lockForUpdate()->firstOrFail();

            if ($proof->isArchived()) {
                throw ValidationException::withMessages(['reason' => __('This proof is archived already.')]);
            }

            $proof->archived_at = CarbonImmutable::now();
            $proof->archived_by_user_id = $member->id;
            $proof->archive_reason = $reason;
            $proof->save();

            activity(PaymentService::LOG)
                ->event('payment_proof.archived')
                ->performedOn($proof)
                ->causedBy($member)
                ->withProperties([
                    'payment_id' => $proof->payment_id,
                    'tenant_profile_id' => $proof->tenant_profile_id,
                    'file_sha256' => $proof->file_sha256,
                    'reason' => $reason,
                ])
                ->log('Proof of payment archived');

            return $proof;
        }, 3);
    }

    /**
     * The organization's other proofs with the same file (same SHA-256),
     * archived ones too, oldest first: what the reviewer is shown.
     *
     * @return Collection<int, PaymentProof>
     */
    public function sameFile(PaymentProof $proof): Collection
    {
        return PaymentProof::query()
            ->where('file_sha256', $proof->file_sha256)
            ->whereKeyNot($proof->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function submit(?User $member, User $by, ProofSubmitter $submitter, int $tenantId, ?int $billId, array $input, UploadedFile $file): PaymentProof
    {
        $values = $this->validated($input);
        $sha256 = $this->checkedFile($file);
        $limits = $this->limits($tenantId, $billId);

        foreach ($limits as [$key, $max, , $message]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['proof' => __($message, ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))])]);
            }
        }

        $proof = DB::transaction(function () use ($member, $by, $submitter, $tenantId, $billId, $values, $sha256, $file): PaymentProof {
            $payment = $this->payments->recordProof($member, $by, $tenantId, $billId, $values['amount'], $values['paidOn'], $values['senderName'], $values['reference'], $values['note']);

            $proof = new PaymentProof;
            $proof->payment_id = $payment->id;
            $proof->tenant_profile_id = $payment->tenant_profile_id;
            $proof->bill_id = $payment->bill_id;
            $proof->amount_kobo = $values['amount'];
            $proof->paid_on = $values['paidOn'];
            $proof->sender_name = $values['senderName'];
            $proof->bank_name = $values['bank'];
            $proof->reference = $values['reference'];
            $proof->note = $values['note'];
            $proof->file_sha256 = $sha256;
            $proof->submitted_by = $submitter;
            $proof->submitted_by_user_id = $by->id;
            $proof->submitted_at = CarbonImmutable::now();
            $proof = $this->insert($proof);

            $media = $this->uploads->add($proof, PaymentProof::COLLECTION, $file, null, ['payment_proof_id' => $proof->id]);
            $proof->media_id = $media->id;
            $proof->save();

            activity(PaymentService::LOG)
                ->event('payment_proof.submitted')
                ->performedOn($proof)
                ->causedBy($by)
                ->withProperties([
                    'payment_id' => $payment->id,
                    'tenant_profile_id' => $proof->tenant_profile_id,
                    'bill_id' => $proof->bill_id,
                    'amount_kobo' => $proof->amount_kobo->kobo,
                    'submitted_by' => $submitter->value,
                    'file_sha256' => $sha256,
                    'duplicate_of_proof_id' => $proof->duplicate_of_proof_id,
                ])
                ->log($proof->duplicate_of_proof_id === null ? 'Proof of payment submitted' : 'Proof of payment submitted (same file as an earlier proof)');

            return $proof;
        }, 3);

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $proof;
    }

    /**
     * Insert the proof as the first with its file, or — when the
     * organization has one already (the unique key refuses it; a racing
     * upload's insert is waited for) — as a duplicate of that one.
     */
    private function insert(PaymentProof $proof): PaymentProof
    {
        $proof->first_upload_sha256 = $proof->file_sha256;

        try {
            DB::transaction(fn (): bool => $proof->save());

            return $proof;
        } catch (UniqueConstraintViolationException $duplicate) {
            // A locking read: it sees the first proof even if it committed
            // after this transaction began.
            $first = PaymentProof::query()->where('first_upload_sha256', $proof->file_sha256)->lockForUpdate()->first();

            if (! $first instanceof PaymentProof) {
                throw $duplicate;
            }

            $proof->first_upload_sha256 = null;
            $proof->duplicate_of_proof_id = $first->id;
            $proof->save();

            return $proof;
        }
    }

    /**
     * The file's SHA-256 once UploadValidator accepts it for the collection.
     *
     * @throws ValidationException
     */
    private function checkedFile(UploadedFile $file): string
    {
        try {
            return $this->validator->check(PaymentProof::COLLECTION, $file)->sha256;
        } catch (UploadRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }
    }

    /**
     * The rate limits that apply: [key, max, decay seconds, message].
     *
     * @return list<array{string, int, int, string}>
     */
    private function limits(int $tenantId, ?int $billId): array
    {
        $organizationId = $this->context->id();
        $limits = [];

        if ($billId !== null) {
            $limits[] = ["payment-proofs:bill:{$organizationId}:{$billId}", self::PER_BILL_PER_HOUR, 3600, 'Too many proofs were sent for this bill. Try again in :minutes minutes.'];
        }

        $limits[] = ["payment-proofs:tenant:{$organizationId}:{$tenantId}", self::PER_TENANT_PER_DAY, 86400, 'Too many proofs were sent for this tenant today. Try again in :minutes minutes.'];

        return $limits;
    }

    /**
     * The proof's details, checked and typed.
     *
     * @param  array<string, mixed>  $input
     * @return array{amount: Money, paidOn: CarbonImmutable, senderName: string, bank: string, reference: string|null, note: string|null}
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $values = [
            'amount' => self::text($input['amount'] ?? null),
            'paidOn' => self::text($input['paidOn'] ?? null),
            'senderName' => self::text($input['senderName'] ?? null),
            'bank' => self::text($input['bank'] ?? null),
            'reference' => self::text($input['reference'] ?? null),
            'note' => self::text($input['note'] ?? null),
        ];
        $today = PaymentDates::today();

        Validator::make($values, [
            'amount' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                $amount = is_string($value) ? NairaInput::parse($value) : null;

                if ($amount === null) {
                    $fail(__('Enter an amount in naira, such as 150,000.'));
                } elseif (! $amount->isPositive()) {
                    $fail(__('Enter an amount above ₦0.'));
                } elseif ($amount->greaterThan(Money::naira(NairaInput::MAX_NAIRA))) {
                    $fail(__('Enter an amount below ₦10,000,000,000.'));
                }
            }],
            'paidOn' => ['required', static function (string $attribute, mixed $value, Closure $fail) use ($today): void {
                $date = PaymentDates::parse(is_string($value) ? $value : null);

                if ($date === null) {
                    $fail(__('Enter the date you paid.'));
                } elseif ($date->greaterThan($today)) {
                    $fail(__('The date paid cannot be after today.'));
                }
            }],
            'senderName' => ['required', 'string', 'max:150'],
            'bank' => ['required', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => __('Enter the amount paid.'),
            'paidOn.required' => __('Enter the date you paid.'),
            'senderName.required' => __('Enter the name on the account that paid.'),
            'bank.required' => __('Enter the bank the money was sent from.'),
        ], [
            'paidOn' => 'date paid',
            'senderName' => 'sender\'s name',
        ])->validate();

        return [
            'amount' => NairaInput::parse((string) $values['amount']) ?? Money::zero(),
            'paidOn' => PaymentDates::parse($values['paidOn']) ?? $today,
            'senderName' => (string) $values['senderName'],
            'bank' => (string) $values['bank'],
            'reference' => $values['reference'],
            'note' => $values['note'],
        ];
    }

    /** Trimmed text with runs of spaces made one; null when empty or not text. */
    private static function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }
}
