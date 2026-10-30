<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoProperty's invoices to its customers (DATABASE_SCHEMA.md § 3
 * `platform_invoices`; BILLING_PAYMENTS.md § 3). Built in P05-03 for SMS
 * credit bundles bought in the app (`kind` sms_credits, `sms_credits` the
 * bundle); P06-02 adds the subscription invoices and their columns
 * (`subscription_id`, `units_billed`, the period). Organization-scoped.
 *
 * - `number`: `PR-{YYYY}-{seq}` from platform_invoice_counters.
 * - `subtotal_kobo` + `vat_kobo` (7.5 %, BILLING_PAYMENTS.md § 1.4) = `total_kobo`.
 * - `status`: open → paid | void (a checkout that failed or was abandoned).
 * - `created_by_user_id`: the member who started the purchase.
 *
 * Never deleted; a paid invoice never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->string('kind', 20);
            $table->unsignedInteger('sms_credits')->nullable();
            $table->unsignedBigInteger('subtotal_kobo');
            $table->unsignedBigInteger('vat_kobo');
            $table->unsignedBigInteger('total_kobo');
            $table->string('status', 15);
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'kind', 'created_at'], 'platform_invoices_org_kind_index');
            $table->index(['organization_id', 'status'], 'platform_invoices_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
