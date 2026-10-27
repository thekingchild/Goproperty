<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The transactions of uploaded bank statements (DATABASE_SCHEMA.md § 4
 * `bank_statement_lines`, ONBOARDING_COMPLIANCE.md § 4.1): one row per
 * credit or debit, kept once however many statements show it. Money is
 * integer kobo (AGENT.md § 3 rule 2): `amount_kobo` is the line's size,
 * `direction` says which way it went, `balance_kobo` is the running
 * balance the bank printed (signed: an overdrawn account), if any.
 *
 * `line_hash` is unique per organization (Modules\Collections\Statements\
 * LineHash): bank account, date, direction, amount, normalised narration,
 * running balance and bank reference when the statement has them, and the
 * line's `occurrence` among identical lines of the same statement. The same
 * statement uploaded again, or an overlapping one, adds no line twice;
 * two genuinely identical credits of the same day stay two lines.
 *
 * Only credits are offered for matching (P03-07b); debits are kept as
 * `ignored`. `suggested_bill_id` and `payment_id` are filled by matching
 * (no foreign keys across modules). Lines are the organization's history:
 * never deleted, and they stay with it in a handover (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_import_id')->constrained('bank_statement_imports')->restrictOnDelete();
            $table->foreignId('organization_bank_account_id')->constrained('organization_bank_accounts')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('transaction_date');
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('narration', 500)->default('');
            $table->string('counterparty_name', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->bigInteger('balance_kobo')->nullable();
            $table->char('line_hash', 64);
            $table->unsignedSmallInteger('occurrence')->default(1);
            $table->string('match_status', 20)->default('unmatched');
            $table->string('match_confidence', 10)->nullable();
            $table->unsignedBigInteger('suggested_bill_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'line_hash'], 'bank_statement_lines_org_hash_unique');
            $table->index(['organization_id', 'bank_statement_import_id', 'row_number'], 'bank_statement_lines_org_import_index');
            $table->index(['organization_id', 'organization_bank_account_id', 'transaction_date'], 'bank_statement_lines_org_account_date_index');
            $table->index(['organization_id', 'direction', 'match_status'], 'bank_statement_lines_org_match_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
