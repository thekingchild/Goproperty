<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Documents\Verification\DocumentVerification;
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
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportColumnMapping;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Models\BillingTransferRequest;
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
            OwnerProfile::class => fn (): Model => OwnerProfile::factory()->withNin()->create(),
            // A batch row on its own (no file): the probes are about the rows.
            ImportBatch::class => fn (): Model => ImportBatch::query()->create(['kind' => 'workbook', 'file_type' => ImportFileType::Xlsx, 'file_name' => 'probe.xlsx']),
            ImportColumnMapping::class => fn (): Model => ImportColumnMapping::query()->create(['kind' => 'tenants', 'name' => 'Probe '.Str::random(6), 'mapping' => ['phone' => 'phone']]),
            Property::class => fn (): Model => Property::factory()->ownedBy(OwnerProfile::factory()->create())->create(),
            Unit::class => fn (): Model => Unit::factory()->in(Property::factory()->create())->create(),
            AgentAssignment::class => fn (): Model => AgentAssignment::factory()->of(User::factory()->create(), Property::factory()->create())->create(),
            TenantProfile::class => fn (): Model => TenantProfile::factory()->withNin()->create(),
            // Owned by the offering organization; the receiving one reads it
            // only through Modules\SaasBilling\Transfers\IncomingTransfers.
            BillingTransferRequest::class => fn (): Model => tap(new BillingTransferRequest, fn (BillingTransferRequest $transfer): bool => $transfer->forceFill([
                'to_organization_id' => Organization::factory()->create()->id,
                'expires_at' => now()->addDays(14),
            ])->save()),
        ];
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
