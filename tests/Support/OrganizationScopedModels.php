<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Documents\DocumentCounter;
use App\Documents\DocumentType;
use App\Documents\Verification\DocumentVerification;
use App\Documents\Verification\VerificationCodes;
use App\Enums\ChecklistItem;
use App\Enums\ComplianceDocumentType;
use App\Enums\OnboardingStep;
use App\Enums\TransactionTier;
use App\Media\Media;
use App\Models\ComplianceDocument;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\OrganizationOnboarding;
use App\Models\TierApplication;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\MeterValue;
use Modules\Collections\Enums\DateOrder;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\StatementFormat;
use Modules\Collections\Models\BankStatementColumnMapping;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Enums\ExpensePaymentMethod;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;
use Modules\Handovers\Models\HandoverBuilding;
use Modules\Handovers\Models\HandoverTenant;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportColumnMapping;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Enums\DepositEntryType;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Modules\Leasing\Models\LeaseDepositEntry;
use Modules\OnlinePayments\Enums\ConnectionMode;
use Modules\OnlinePayments\Enums\ConnectionStatus;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\Models\PaystackTransaction;
use Modules\OnlinePayments\Models\PaystackWebhookLog;
use Modules\OwnerStatements\Enums\SettlementStatus;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Models\OwnerStatementTerms;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\CreditReason;
use Modules\Payments\Enums\ProofSubmitter;
use Modules\Payments\Models\OpeningBalance;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\PaymentNotice;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Models\TenantCreditBalance;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

/**
 * Every organization-owned model (BelongsToOrganization) and how to make one
 * in the current organization, for the cross-tenant probes in
 * tests/Feature/Tenancy/CrossTenantAccessTest.php (IMPLEMENTATION_PLAN.md
 * P01 exit: cross-tenant tests cover every Phase-1 model).
 *
 * A task that adds an organization-owned model adds it here; the probe test
 * fails while any model in app/ or Modules/ is missing.
 */
final class OrganizationScopedModels
{
    /** @return array<class-string<Model>, Closure(): Model> */
    public static function factories(): array
    {
        return [
            Membership::class => fn (): Model => Membership::query()->create(['user_id' => User::factory()->create()->id]),
            OrganizationOnboarding::class => fn (): Model => OrganizationOnboarding::query()->create(['step' => OnboardingStep::Tier]),
            OnboardingChecklistItem::class => fn (): Model => OnboardingChecklistItem::query()->create(['item' => ChecklistItem::Plan]),
            OrganizationBankAccount::class => fn (): Model => OrganizationBankAccount::query()->create([
                'bank_code' => '058',
                'bank_name' => 'Guaranty Trust Bank (GTBank)',
                'account_number' => $number = (string) random_int(1_000_000_000, 9_999_999_999),
                'account_number_last4' => substr($number, -4),
                'account_fingerprint' => hash('sha256', $number),
                'account_name' => 'Probe Realty Ltd',
            ]),
            Invitation::class => fn (): Model => tap(new Invitation, fn (Invitation $invitation): bool => $invitation->forceFill([
                'email' => fake()->unique()->safeEmail(),
                'roles' => ['agent'],
                'token_hash' => hash('sha256', fake()->unique()->sha256()),
                'expires_at' => now()->addDays(14),
            ])->save()),
            TierApplication::class => fn (): Model => TierApplication::query()->create(['from_tier' => TransactionTier::One, 'requested_tier' => TransactionTier::Two]),
            ComplianceDocument::class => fn (): Model => ComplianceDocument::query()->create([
                'type' => ComplianceDocumentType::ScumlCertificate,
                'disk' => 'compliance',
                'path' => 'probe/'.Str::uuid()->toString().'.enc',
                'original_name' => 'scuml.pdf',
                'mime' => 'application/pdf',
                'size_bytes' => 1024,
                'sha256' => str_repeat('0', 64),
                'document_number' => 'SCUML-123',
            ]),
            // A media row on its own (no file): the probes are about the rows.
            Media::class => fn (): Model => Media::query()->create([
                'model_type' => Organization::class,
                'model_id' => app(OrganizationContext::class)->id(),
                'collection_name' => 'photos',
                'name' => 'probe',
                'file_name' => Str::random(40).'.jpg',
                'mime_type' => 'image/jpeg',
                'disk' => 'media',
                'conversions_disk' => 'media',
                'size' => 1024,
                'manipulations' => [],
                'custom_properties' => [],
                'generated_conversions' => [],
                'responsive_images' => [],
            ]),
            DocumentVerification::class => fn (): Model => DocumentVerification::query()->create(['kind' => 'probe', 'code' => strtoupper(Str::random(10))]),
            DocumentCounter::class => fn (): Model => DocumentCounter::query()->create(['doc_type' => DocumentType::Invoice, 'year' => 2026, 'last_seq' => 1]),
            OwnerProfile::class => fn (): Model => OwnerProfile::factory()->withNin()->create(),
            // A batch row on its own (no file): the probes are about the rows.
            ImportBatch::class => fn (): Model => ImportBatch::query()->create(['kind' => 'workbook', 'file_type' => ImportFileType::Xlsx, 'file_name' => 'probe.xlsx']),
            ImportColumnMapping::class => fn (): Model => ImportColumnMapping::query()->create(['kind' => 'tenants', 'name' => 'Probe '.Str::random(6), 'mapping' => ['phone' => 'phone']]),
            Property::class => fn (): Model => Property::factory()->ownedBy(OwnerProfile::factory()->create())->create(),
            Unit::class => fn (): Model => Unit::factory()->in(Property::factory()->create())->create(),
            AgentAssignment::class => fn (): Model => AgentAssignment::factory()->of(User::factory()->create(), Property::factory()->create())->create(),
            TenantProfile::class => fn (): Model => TenantProfile::factory()->withNin()->create(),
            // Rows on their own (LeaseService is the writer; the probes are about the rows).
            Lease::class => fn (): Model => self::lease(),
            LeaseDepositEntry::class => fn (): Model => tap(new LeaseDepositEntry, fn (LeaseDepositEntry $entry): bool => $entry->forceFill([
                'lease_id' => self::lease()->id,
                'type' => DepositEntryType::Held,
                'amount_kobo' => Money::naira(150_000),
            ])->save()),
            // An issued contract (P03-03b): history, never changed or deleted.
            LeaseContract::class => fn (): Model => tap(new LeaseContract, fn (LeaseContract $contract): bool => $contract->forceFill([
                'lease_id' => self::lease()->id,
                'contract_number' => 'LS-TST-2027-'.random_int(100000, 999999),
                'verification_code' => app(VerificationCodes::class)->generate(),
                'branding_snapshot' => ['version' => 1],
                'details' => [],
                'clauses' => 'The tenant pays the rent.',
                'issued_at' => now(),
            ])->save()),
            // Rows on their own (BillIssuer is the writer; the probes are about the rows).
            Bill::class => fn (): Model => self::bill(),
            BillItem::class => fn (): Model => tap(new BillItem, fn (BillItem $item): bool => $item->forceFill([
                'bill_id' => self::bill()->id,
                'label' => 'Rent',
                'amount_kobo' => Money::naira(1_500_000),
            ])->save()),
            // Rows on their own (Utilities\UtilityTypes, UnitUtilitySettings and MeterReadings are the writers; P03-06a).
            UtilityType::class => fn (): Model => self::utilityType(),
            UnitUtilitySetting::class => fn (): Model => self::utilitySetting(),
            UtilityReading::class => fn (): Model => tap(new UtilityReading, fn (UtilityReading $reading): bool => $reading->forceFill([
                'unit_utility_setting_id' => self::utilitySetting()->id,
                'reading_date' => '2027-01-31',
                'previous_value' => MeterValue::zero(),
                'current_value' => MeterValue::parse('120.5'),
                'consumption' => MeterValue::parse('120.5'),
                'rate_kobo' => Money::naira(250),
            ])->save()),
            // Rows on their own (PaymentService and TenantCredit are the writers; the probes are about the rows).
            Payment::class => fn (): Model => self::payment(),
            PaymentAllocation::class => fn (): Model => tap(new PaymentAllocation, fn (PaymentAllocation $allocation): bool => $allocation->forceFill([
                'payment_id' => self::payment()->id,
                'bill_id' => self::bill()->id,
                'amount_kobo' => Money::naira(100_000),
            ])->save()),
            TenantCreditEntry::class => fn (): Model => tap(new TenantCreditEntry, fn (TenantCreditEntry $entry): bool => $entry->forceFill([
                'tenant_profile_id' => TenantProfile::factory()->create()->id,
                'amount_kobo' => Money::naira(50_000),
                'reason' => CreditReason::Overpayment,
            ])->save()),
            OpeningBalance::class => fn (): Model => tap(new OpeningBalance, fn (OpeningBalance $balance): bool => $balance->forceFill([
                'tenant_profile_id' => TenantProfile::factory()->create()->id,
                'as_of_date' => '2026-09-30',
                'amount_kobo' => Money::naira(-50_000),
            ])->save()),
            TenantCreditBalance::class => fn (): Model => tap(new TenantCreditBalance, fn (TenantCreditBalance $balance): bool => $balance->forceFill([
                'tenant_profile_id' => TenantProfile::factory()->create()->id,
                'balance_kobo' => Money::naira(50_000),
            ])->save()),
            // Rows on their own (StatementImports and StatementReader are the writers; the probes are about the rows).
            BankStatementImport::class => fn (): Model => self::statement(),
            BankStatementLine::class => fn (): Model => tap(new BankStatementLine, function (BankStatementLine $line): bool {
                $statement = self::statement();

                return $line->forceFill([
                    'bank_statement_import_id' => $statement->id,
                    'organization_bank_account_id' => $statement->organization_bank_account_id,
                    'row_number' => 2,
                    'transaction_date' => '2027-01-02',
                    'direction' => LineDirection::Credit,
                    'amount_kobo' => Money::naira(100_000),
                    'narration' => 'Probe transfer',
                    'line_hash' => hash('sha256', Str::random(40)),
                ])->save();
            }),
            BankStatementColumnMapping::class => fn (): Model => tap(new BankStatementColumnMapping, fn (BankStatementColumnMapping $mapping): bool => $mapping->forceFill([
                'organization_bank_account_id' => self::make(OrganizationBankAccount::class)->getKey(),
                'columns' => ['date' => 'Date', 'narration' => 'Narration', 'credit' => 'Credit'],
                'date_order' => DateOrder::DayFirst,
            ])->save()),
            // An issued receipt and a notice (P03-10b): history, never changed or deleted.
            Receipt::class => fn (): Model => tap(new Receipt, function (Receipt $receipt): void {
                $payment = self::payment();
                $receipt->forceFill([
                    'payment_id' => $payment->id,
                    'tenant_profile_id' => $payment->tenant_profile_id,
                    'number' => 'RCT-PRB-2027-'.random_int(100_000, 999_999),
                    'verification_code' => app(VerificationCodes::class)->generate(),
                    'amount_kobo' => Money::naira(100_000),
                    'issued_at' => now(),
                    'branding_snapshot' => ['version' => 1],
                    'details' => [],
                ])->save();
            }),
            PaymentNotice::class => fn (): Model => tap(new PaymentNotice, fn (PaymentNotice $notice): bool => $notice->forceFill([
                'payment_id' => self::payment()->id,
                'event' => 'payment.recorded',
                'channels' => ['mail'],
            ])->save()),
            // The organization's own Paystack (P03-08): PaystackConnections and PaystackTransactions are the writers.
            PaystackConnection::class => fn (): Model => tap(new PaystackConnection, fn (PaystackConnection $connection): bool => $connection->forceFill([
                'public_key' => 'pk_live_'.Str::lower(Str::random(40)),
                'secret_key' => 'sk_live_'.($secret = Str::lower(Str::random(40))),
                'secret_key_last4' => substr($secret, -4),
                'webhook_token' => Str::lower(Str::random(48)),
                'mode' => ConnectionMode::Live,
                'status' => ConnectionStatus::Active,
            ])->save()),
            PaystackWebhookLog::class => fn (): Model => tap(new PaystackWebhookLog, fn (PaystackWebhookLog $log): bool => $log->forceFill([
                'event' => 'charge.success',
                'reference' => 'GPO-PROBE',
                'payload' => '{"event":"charge.success"}',
                'signature_valid' => true,
                'received_at' => now(),
            ])->save()),
            PaystackTransaction::class => fn (): Model => tap(new PaystackTransaction, function (PaystackTransaction $transaction): void {
                $bill = self::bill();
                $transaction->forceFill([
                    'bill_id' => $bill->id,
                    'tenant_profile_id' => $bill->tenant_profile_id,
                    'reference' => 'GPO-'.strtoupper(bin2hex(random_bytes(10))),
                    'amount_kobo' => Money::naira(1_500_000),
                    'mode' => ConnectionMode::Live,
                ])->save();
            }),
            // A proof of payment (P03-09) on its own (no file): evidence, never changed or deleted.
            PaymentProof::class => fn (): Model => tap(new PaymentProof, function (PaymentProof $proof): void {
                $payment = self::payment();
                $proof->forceFill([
                    'payment_id' => $payment->id,
                    'tenant_profile_id' => $payment->tenant_profile_id,
                    'amount_kobo' => Money::naira(100_000),
                    'paid_on' => '2027-01-01',
                    'sender_name' => 'Probe Sender',
                    'bank_name' => 'Probe Bank',
                    'file_sha256' => $hash = hash('sha256', Str::random(32)),
                    'first_upload_sha256' => $hash,
                    'submitted_by' => ProofSubmitter::Member,
                ])->save();
            }),
            // Rows on their own (Recording\ExpenseBook is the writer; the probes are about the rows).
            Expense::class => fn (): Model => self::expense(),
            ExpenseReceipt::class => fn (): Model => tap(new ExpenseReceipt, fn (ExpenseReceipt $receipt): bool => $receipt->forceFill([
                'expense_id' => self::expense()->id,
                'media_id' => self::make(Media::class)->getKey(),
                'file_sha256' => hash('sha256', Str::random(32)),
            ])->save()),
            // Owned by the offering organization; the receiving one reads it
            // only through Modules\Handovers\Handovers\IncomingHandovers.
            ManagementHandover::class => fn (): Model => self::handover(),
            HandoverBuilding::class => fn (): Model => tap(new HandoverBuilding, fn (HandoverBuilding $building): bool => $building->forceFill([
                'management_handover_id' => self::handover()->id,
                'property_id' => Property::factory()->create()->id,
                'property_name' => 'Probe Court',
                'unit_count' => 2,
            ])->save()),
            HandoverTenant::class => fn (): Model => tap(new HandoverTenant, fn (HandoverTenant $line): bool => $line->forceFill([
                'management_handover_id' => self::handover()->id,
                'tenant_profile_id' => TenantProfile::factory()->create()->id,
                'tenant_name' => 'Probe Tenant',
            ])->save()),
            // Owner statements (P08-05): StatementTermsBook and Settlements are the writers; the probes are about the rows.
            OwnerStatementTerms::class => fn (): Model => tap(new OwnerStatementTerms, fn (OwnerStatementTerms $terms): bool => $terms->forceFill([
                'owner_profile_id' => OwnerProfile::factory()->create()->id,
                'commission_bps' => 1000,
            ])->save()),
            OwnerStatementSettlement::class => fn (): Model => tap(new OwnerStatementSettlement, fn (OwnerStatementSettlement $settlement): bool => $settlement->forceFill([
                'owner_profile_id' => OwnerProfile::factory()->create()->id,
                'month' => '2027-01-01',
                'status' => SettlementStatus::Settled,
                'paid_on' => '2027-02-02',
                'reference' => 'TRF-PROBE',
                'net_due_kobo' => 100_000,
                'figures' => ['format' => 1],
                'version' => 1,
            ])->save()),
        ];
    }

    /** A pending handover offered by the current organization. */
    private static function handover(): ManagementHandover
    {
        return tap(new ManagementHandover, fn (ManagementHandover $handover): bool => $handover->forceFill([
            'to_organization_id' => Organization::factory()->create()->id,
            'landlord_organization_id' => app(OrganizationContext::class)->id(),
            'expires_at' => now()->addDays(14),
        ])->save());
    }

    /** An expense of a new building, in the current organization. */
    private static function expense(): Expense
    {
        return tap(new Expense, fn (Expense $expense): bool => $expense->forceFill([
            'property_id' => Property::factory()->create()->id,
            'category' => ExpenseCategory::Repairs,
            'description' => 'Probe repair',
            'amount_kobo' => Money::naira(25_000),
            'spent_on' => '2027-01-01',
            'payment_method' => ExpensePaymentMethod::Cash,
        ])->save());
    }

    /** A draft lease of a new unit for a new tenant, in the current organization. */
    private static function lease(): Lease
    {
        $unit = Unit::factory()->in(Property::factory()->create())->create();

        return tap(new Lease, fn (Lease $lease): bool => $lease->forceFill([
            'property_id' => $unit->property_id,
            'unit_id' => $unit->id,
            'tenant_profile_id' => TenantProfile::factory()->create()->id,
            'billing_cycle' => BillingCycle::Annual,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'rent_amount_kobo' => Money::naira(1_500_000),
        ])->save());
    }

    /** A rent bill of a new draft lease, in the current organization. */
    private static function bill(): Bill
    {
        $lease = self::lease();

        return tap(new Bill, fn (Bill $bill): bool => $bill->forceFill([
            'type' => BillType::Rent,
            'billable_type' => BillableType::Lease,
            'billable_id' => $lease->id,
            'lease_id' => $lease->id,
            'tenant_profile_id' => $lease->tenant_profile_id,
            'property_id' => $lease->property_id,
            'unit_id' => $lease->unit_id,
            'payment_reference' => 'GP-'.strtoupper(Str::random(7)),
            'number' => 'INV-PRB-2027-'.random_int(100_000, 999_999),
            'description' => 'Rent, 01/01/2027 to 31/12/2027',
            'amount_kobo' => Money::naira(1_500_000),
            'due_date' => '2027-01-01',
            'period_start' => '2027-01-01',
            'period_end' => '2027-12-31',
            'issued_at' => now(),
            'branding_snapshot' => ['version' => 1],
            'verification_code' => strtoupper(Str::random(10)),
        ])->save());
    }

    /** A metered utility type with a unique code, in the current organization. */
    private static function utilityType(): UtilityType
    {
        $code = 'PRB-'.strtoupper(Str::random(8));

        return tap(new UtilityType, fn (UtilityType $type): bool => $type->forceFill([
            'name' => 'Probe electricity '.$code,
            'code' => $code,
            'charging_mode' => ChargingMode::Metered,
            'unit_of_measure' => MeterUnit::Kwh,
            'default_amount_kobo' => Money::naira(250),
            'default_cycle' => BillingCycle::Monthly,
        ])->save());
    }

    /** A new unit's metered utility, in the current organization. */
    private static function utilitySetting(): UnitUtilitySetting
    {
        $unit = Unit::factory()->in(Property::factory()->create())->create();

        return tap(new UnitUtilitySetting, fn (UnitUtilitySetting $setting): bool => $setting->forceFill([
            'property_id' => $unit->property_id,
            'unit_id' => $unit->id,
            'utility_type_id' => self::utilityType()->id,
            'effective_from' => '2027-01-01',
        ])->save());
    }

    /** A payment waiting for confirmation from a new tenant, in the current organization. */
    private static function payment(): Payment
    {
        return tap(new Payment, fn (Payment $payment): bool => $payment->forceFill([
            'tenant_profile_id' => TenantProfile::factory()->create()->id,
            'amount_kobo' => Money::naira(100_000),
            'method' => PaymentMethod::BankTransfer,
            'source' => PaymentSource::Manual,
            'paid_at' => '2027-01-01',
        ])->save());
    }

    /** An uploaded statement of a new bank account, in the current organization. */
    private static function statement(): BankStatementImport
    {
        return tap(new BankStatementImport, fn (BankStatementImport $statement): bool => $statement->forceFill([
            'organization_bank_account_id' => self::make(OrganizationBankAccount::class)->getKey(),
            'file_name' => 'probe.csv',
            'format' => StatementFormat::Csv,
        ])->save());
    }

    /** @param  class-string<Model>  $class */
    public static function make(string $class): Model
    {
        $factory = self::factories()[$class] ?? throw new LogicException("{$class} is not in ".self::class.'.');

        return $factory();
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function dataset(): array
    {
        $dataset = [];

        foreach (array_keys(self::factories()) as $class) {
            $dataset[class_basename($class)] = [$class];
        }

        return $dataset;
    }
}
