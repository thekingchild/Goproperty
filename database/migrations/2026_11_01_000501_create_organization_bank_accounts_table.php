<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organization's own bank accounts that tenants pay into
 * (ONBOARDING_COMPLIANCE.md § 4.1, DATABASE_SCHEMA.md § 4). The account
 * number is encrypted (a text column: ciphertext is long and differs on each
 * write), so `account_number_last4` keeps the masked display and
 * `account_fingerprint` (HMAC of bank code and number with the app key)
 * finds a duplicate without decrypting. `name_check_status` records the
 * Paystack account-name lookup: matched, mismatched, not_found, or
 * unchecked when no lookup could be made. Soft deletes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('bank_code', 12);
            $table->string('bank_name', 100);
            $table->text('account_number');
            $table->char('account_number_last4', 4);
            $table->char('account_fingerprint', 64);
            $table->string('account_name', 150);
            $table->string('name_check_status', 20)->default('unchecked');
            $table->string('name_check_result', 150)->nullable();
            $table->timestamp('name_checked_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active', 'is_default'], 'org_bank_accounts_org_active_default_index');
            $table->index(['organization_id', 'account_fingerprint'], 'org_bank_accounts_org_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_bank_accounts');
    }
};
