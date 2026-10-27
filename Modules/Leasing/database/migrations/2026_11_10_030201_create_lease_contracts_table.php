<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issued lease contracts (P03-03b; DATABASE_SCHEMA.md § 3 `lease_contracts`,
 * BRANDING_DOCUMENTS.md § 4–5): one row per activated lease, written once
 * right after the activation commits by Modules\Leasing\Documents\LeaseContracts.
 * It keeps what the printed contract shows as it was at issue — the
 * agency's branding (App\Documents\BrandingSnapshot, images embedded), the
 * parties and the rent schedule (`details`: tenant, building, unit,
 * landlord, first due date), the agency's clause template — and the
 * verification code (App\Documents\Verification, kind
 * `lease_contract`). The PDF is rendered once from this row and kept as
 * evidence in the row's `issued_documents` media (`document_media_id`).
 *
 * History, not a live record: it stays with the organization that issued
 * it even when a management handover (P03-14) moves the lease, and it is
 * never changed or deleted (AGENT.md § 3 rule 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->unique()->constrained('leases')->restrictOnDelete();
            $table->string('contract_number', 40);
            $table->char('verification_code', 10)->unique();
            $table->json('branding_snapshot');
            $table->json('details');
            $table->text('clauses');
            // An explicit default: on MariaDB 10.6 a bare NOT NULL timestamp would rewrite itself on every update.
            $table->timestamp('issued_at')->useCurrent();
            $table->unsignedBigInteger('document_media_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'lease_id'], 'lease_contracts_org_lease_index');
            $table->unique(['organization_id', 'contract_number'], 'lease_contracts_org_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_contracts');
    }
};
