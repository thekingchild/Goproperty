<?php

declare(strict_types=1);

namespace Modules\Payments\Import;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Models\Payment;
use Modules\Payments\Payments\ImportedPayment;
use Modules\Payments\Payments\PaymentDates;
use Modules\Payments\Payments\PaymentService;
use Modules\Tenants\Contracts\ImportedTenants;
use Modules\Tenants\Contracts\TenantReference;
use WeakMap;

/**
 * The Payment history sheet (DATA_IMPORT.md § 1 row 8, § 3; P03-13b): the
 * payments tenants made before the agency moved to GoProperty, "for records
 * and statements only". Each row becomes a confirmed payment of source
 * `import` (PaymentService::importHistory) that settles NO bill and adds NO
 * credit — what the tenant still owed or had in credit afterwards is the
 * Opening balances sheet's — so history never counts twice.
 *
 * No receipt and no notice by default: a receipt is issued (numbered now,
 * in today's sequence) only for a row whose "Issue receipt" says Yes, and
 * the tenant is never told either way (imports contact nobody; the portal
 * shows an issued receipt). A row matches a payment by Your ID, else by
 * tenant + date + amount + method + reference, rolled-back ones included:
 * re-importing changes nothing (a confirmed payment never changes) and
 * brings a rolled-back one back. Rollback takes the batch's payments back
 * and is refused once one has a receipt.
 *
 * Tenants are read through the Tenants module's ImportedTenants contract.
 */
final class PaymentHistoryImportTarget implements ImportTarget
{
    public const string KEY = 'payment_history';

    private const string ROWS_BY_REF = 'payment_history:ref';

    private const string ROWS_BY_KEY = 'payment_history:key';

    private const int MAX_NAIRA = 10_000_000_000;

    /** @var WeakMap<ImportRun, array<string, TenantReference>> */
    private WeakMap $tenantRefs;

    /**
     * Holds no collaborator: this target is a singleton, so whatever reads
     * the organization (its context is scoped) is resolved when used.
     */
    public function __construct()
    {
        $this->tenantRefs = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Payment history';
    }

    /** Last (DATA_IMPORT.md § 2.4): after the tenants it names and their balances. */
    public function order(): int
    {
        return 80;
    }

    public function instructions(): string
    {
        return 'Optional: payments tenants made before you moved to GoProperty, for your records and statements. They settle no bill and add no credit (put what is still owed or in credit on the Opening balances sheet). No receipt is issued unless you put Yes under "Issue receipt", and tenants are not told.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('P-001'),
            new ImportColumn('tenant', 'Tenant', required: true, aliases: ['tenant phone', 'tenant phone number', 'tenant id', 'tenant ref', 'phone', 'phone number', 'tenant mobile', 'payer'], example: '0803 123 4567', help: 'The tenant\'s phone number, or Your ID from the Tenants sheet.'),
            new ImportColumn('paid_at', 'Date paid', required: true, aliases: ['date', 'payment date', 'paid on', 'date of payment', 'value date'], example: '15/12/2025', help: 'DD/MM/YYYY, today or earlier.'),
            new ImportColumn('amount', 'Amount', required: true, aliases: ['amount paid', 'paid', 'payment', 'payment amount', 'credit'], example: '₦1,500,000'),
            new ImportColumn('method', 'Method', required: true, aliases: ['payment method', 'paid by', 'channel', 'mode', 'payment mode'], example: PaymentMethod::BankTransfer->label(), options: array_map(static fn (PaymentMethod $method): string => $method->label(), PaymentMethod::cases())),
            new ImportColumn('reference', 'Reference', aliases: ['payment reference', 'transaction reference', 'ref no', 'reference number', 'teller number', 'receipt number'], example: 'TRF-2025-1215', help: 'The bank or teller reference, if any.'),
            new ImportColumn('note', 'Note', aliases: ['notes', 'remarks', 'comment', 'narration', 'description'], example: ''),
            new ImportColumn('issue_receipt', 'Issue receipt', aliases: ['receipt', 'issue a receipt', 'send receipt'], example: 'No', help: 'Yes: GoProperty issues a numbered receipt for it now (the tenant is not sent it). Empty: No.', options: ['Yes', 'No']),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = $this->refIssues($row, $run);
        $tenant = ($text = $row->text('tenant')) !== null ? $this->tenant($text, $run) : null;

        if ($tenant === null) {
            $issues[] = RowIssue::error('tenant', __('Enter the tenant\'s phone number or Your ID from the Tenants sheet.'));
        } elseif ($tenant->error !== null) {
            $issues[] = RowIssue::error('tenant', $tenant->error);
        }

        $paidAt = $this->dateCell($row, $issues);
        $amount = $this->amountCell($row, $issues);
        $method = $this->methodCell($row, $issues);
        $receipt = $this->receiptCell($row, $issues);
        $issues = [...$issues, ...self::plainText($row, 'reference', 100), ...self::plainText($row, 'note', 500)];

        if (self::hasErrors($issues) || $tenant === null || $paidAt === null || $amount === null || $method === null) {
            return $issues;
        }

        $key = $this->naturalKey($tenant, $paidAt, $amount, $method, $row->text('reference'));

        if ($row->externalRef() === null && ($earlier = $run->rowOf(self::ROWS_BY_KEY, $key)) !== null) {
            return [...$issues, RowIssue::error(null, __('Row :row has the same payment (tenant, date, amount, method and reference). Give each its own Your ID or reference, or leave the copy out.', ['row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_KEY, $key, $row->number);
        $match = $this->match($row, $tenant, $paidAt, $amount, $method);

        if ($match !== null) {
            if ($tenant->key !== 'id:'.$match->tenant_profile_id || ! $match->paid_at->equalTo($paidAt) || ! $match->amount_kobo->equals($amount) || $match->method !== $method) {
                return [...$issues, RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is a payment on GoProperty already (:amount on :date), and an import does not change a payment. Check the ID.', ['amount' => $match->amount_kobo->format(), 'date' => $match->paid_at->format('d/m/Y')]))];
            }

            $issues[] = $match->trashed()
                ? RowIssue::warning(null, __('This payment was removed when an import was rolled back. Importing brings it back.'))
                : RowIssue::warning(null, __('This payment is on GoProperty already: nothing changes.'));
        }

        if ($receipt === true && ($match === null || $match->receipt_id === null)) {
            $issues[] = RowIssue::warning('issue_receipt', __('A receipt is issued for this payment, numbered today. The tenant is not sent it.'));
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        $tenant = $this->tenant((string) $row->text('tenant'), $run);
        $paidAt = $row->date('paid_at') ?? PaymentDates::today();
        $amount = Money::kobo((int) $row->amountKobo('amount'));
        $method = self::method((string) $row->text('method')) ?? PaymentMethod::BankTransfer;
        $match = $this->match($row, $tenant, $paidAt, $amount, $method);

        return new RowPlan(
            action: match (true) {
                $match === null => ImportAction::Create,
                $match->trashed() => ImportAction::Restore,
                default => ImportAction::Update,
            },
            preview: [
                'tenant' => $tenant->name,
                'paid_at' => $paidAt->format('d/m/Y'),
                'amount' => $amount->format(),
                'method' => $method->label(),
                'reference' => $row->text('reference'),
                'issue_receipt' => self::yes($row->text('issue_receipt')) ? __('Yes') : __('No'),
            ],
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $tenant = $this->tenant((string) $row->text('tenant'), $run);

        if ($tenant->tenant === null) {
            return new UpsertResult(ImportAction::Skip, reason: __('The tenant is no longer on file.'));
        }

        $paidAt = $row->date('paid_at');
        $method = self::method((string) $row->text('method'));
        assert($paidAt !== null && $method !== null);
        $amount = Money::kobo((int) $row->amountKobo('amount'));

        [$action, $payment] = app(PaymentService::class)->importHistory($run->batchId(), new ImportedPayment(
            tenantProfileId: $tenant->tenant->id,
            amount: $amount,
            method: $method,
            paidAt: $paidAt,
            reference: $row->text('reference'),
            note: $row->text('note'),
            externalRef: $row->externalRef(),
            issueReceipt: self::yes($row->text('issue_receipt')),
        ), $this->match($row, $tenant, $paidAt, $amount, $method));

        return new UpsertResult($action, $payment->id);
    }

    public function rollback(int $batchId): int
    {
        return app(PaymentService::class)->rollBackImportedHistory($batchId);
    }

    /** Used once one of the batch's payments has a receipt (an issued document is evidence). */
    public function usedSince(int $batchId): bool
    {
        return Payment::query()->where('import_batch_id', $batchId)->where('source', PaymentSource::Import)->whereNotNull('receipt_id')->exists();
    }

    /** The imported payment the row is about: by Your ID, then by tenant + date + amount + method + reference, rolled-back ones included (live first). */
    private function match(ImportRow $row, TenantReference $tenant, CarbonImmutable $paidAt, Money $amount, PaymentMethod $method): ?Payment
    {
        $ref = $row->externalRef();

        if ($ref !== null) {
            return Payment::query()->withTrashed()->where('external_ref', $ref)->first();
        }

        // By the id behind the key: a tenant the file brings back after a rollback is known by its id too.
        $tenantId = $tenant->key !== null && str_starts_with($tenant->key, 'id:') ? (int) substr($tenant->key, 3) : null;

        if ($tenantId === null) {
            return null;
        }

        $reference = $row->text('reference');

        return Payment::query()->withTrashed()
            ->where('source', PaymentSource::Import)
            ->where('tenant_profile_id', $tenantId)
            ->whereDate('paid_at', $paidAt->toDateString())
            ->where('amount_kobo', $amount->kobo)
            ->where('method', $method)
            ->when($reference === null, fn ($query) => $query->whereNull('reference'), fn ($query) => $query->where('reference', $reference))
            ->whereNull('external_ref')
            ->orderByRaw('deleted_at IS NULL DESC')
            ->orderBy('id')
            ->first();
    }

    private function naturalKey(TenantReference $tenant, CarbonImmutable $paidAt, Money $amount, PaymentMethod $method, ?string $reference): string
    {
        return implode('|', [$tenant->key, $paidAt->toDateString(), $amount->kobo, $method->value, mb_strtolower((string) $reference)]);
    }

    private function tenant(string $reference, ImportRun $run): TenantReference
    {
        $known = $this->tenantRefs[$run] ?? [];

        if (! isset($known[$reference])) {
            $known[$reference] = app(ImportedTenants::class)->resolve($reference, $run);
            $this->tenantRefs[$run] = $known;
        }

        return $known[$reference];
    }

    /** @return list<RowIssue> */
    private function refIssues(ImportRow $row, ImportRun $run): array
    {
        $ref = $row->externalRef();

        if ($ref === null) {
            return [];
        }

        if (mb_strlen($ref) > 100) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('Use at most :max characters for your ID.', ['max' => 100]))];
        }

        if (($earlier = $run->rowOf(self::ROWS_BY_REF, $ref)) !== null) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is already on row :row. Each payment goes on one row.', ['row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_REF, $ref, $row->number);

        return [];
    }

    /** @param  list<RowIssue>  $issues */
    private function dateCell(ImportRow $row, array &$issues): ?CarbonImmutable
    {
        try {
            $date = $row->date('paid_at');
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error('paid_at', $invalid->getMessage());

            return null;
        }

        if ($date === null) {
            $issues[] = RowIssue::error('paid_at', __('Enter the day the tenant paid, as DD/MM/YYYY.'));

            return null;
        }

        if ($date->greaterThan(PaymentDates::today())) {
            $issues[] = RowIssue::error('paid_at', __('A payment in the history was made today or earlier.'));

            return null;
        }

        return $date;
    }

    /** @param  list<RowIssue>  $issues */
    private function amountCell(ImportRow $row, array &$issues): ?Money
    {
        try {
            $kobo = $row->amountKobo('amount');
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error('amount', $invalid->getMessage());

            return null;
        }

        return match (true) {
            $kobo === null => self::refuse($issues, 'amount', __('Enter the amount the tenant paid.')),
            $kobo <= 0 => self::refuse($issues, 'amount', __('Enter an amount above ₦0.')),
            $kobo >= Money::naira(self::MAX_NAIRA)->kobo => self::refuse($issues, 'amount', __('Enter an amount below ₦10,000,000,000.')),
            default => Money::kobo($kobo),
        };
    }

    /** @param  list<RowIssue>  $issues */
    private function methodCell(ImportRow $row, array &$issues): ?PaymentMethod
    {
        $text = $row->text('method');
        $method = $text !== null ? self::method($text) : null;

        if ($method === null) {
            $issues[] = RowIssue::error('method', __('Choose one of: :options.', ['options' => implode(', ', array_map(static fn (PaymentMethod $m): string => $m->label(), PaymentMethod::cases()))]));
        }

        return $method;
    }

    /** @param  list<RowIssue>  $issues */
    private function receiptCell(ImportRow $row, array &$issues): ?bool
    {
        $text = $row->text('issue_receipt');

        if ($text === null) {
            return false;
        }

        $value = self::yesNo($text);

        if ($value === null) {
            $issues[] = RowIssue::error('issue_receipt', __('Enter Yes or No.'));
        }

        return $value;
    }

    /** A payment method as listed, or as agencies write it ("Transfer", "Bank", "Card", "Online"…). */
    private static function method(string $text): ?PaymentMethod
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($text));

        foreach (PaymentMethod::cases() as $method) {
            if ($key === preg_replace('/[^a-z]/', '', mb_strtolower($method->label())) || $key === str_replace('_', '', $method->value)) {
                return $method;
            }
        }

        return match ($key) {
            'transfer', 'bank', 'banktransfer', 'banktrf', 'trf', 'deposit', 'bankdeposit', 'teller', 'nip', 'ussd' => PaymentMethod::BankTransfer,
            'cashpayment', 'incash' => PaymentMethod::Cash,
            'card', 'pointofsale', 'posterminal' => PaymentMethod::Pos,
            'online', 'onlinepayment', 'paystackonline' => PaymentMethod::Paystack,
            default => null,
        };
    }

    /**
     * @param  list<RowIssue>  $issues
     */
    private static function refuse(array &$issues, string $column, string $message): null
    {
        $issues[] = RowIssue::error($column, $message);

        return null;
    }

    /** @return list<RowIssue> */
    private static function plainText(ImportRow $row, string $column, int $max): array
    {
        $text = $row->text($column);

        return match (true) {
            $text === null => [],
            preg_match('/[<>]/', $text) === 1 => [RowIssue::error($column, __('Use plain text only (no < or >).'))],
            mb_strlen($text) > $max => [RowIssue::error($column, __('Use at most :max characters.', ['max' => $max]))],
            default => [],
        };
    }

    private static function yesNo(string $text): ?bool
    {
        return match (mb_strtolower(trim($text))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    private static function yes(?string $text): bool
    {
        return $text !== null && self::yesNo($text) === true;
    }

    /** @param  list<RowIssue>  $issues */
    private static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }
}
