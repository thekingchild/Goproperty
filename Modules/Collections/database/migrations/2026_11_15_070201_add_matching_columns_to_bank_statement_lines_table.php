<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What statement matching (P03-07b, ONBOARDING_COMPLIANCE.md § 4.1) keeps
 * on a credit line besides `match_status`, `match_confidence`,
 * `suggested_bill_id` and `payment_id` (P03-07a):
 *
 * - `tenant_profile_id`: the tenant the line was matched to (the payment's
 *   tenant), for the statement page; cleared when the match is rejected.
 * - `match_attempts`: how many payments have been recorded from the line.
 *   The first is keyed `statement-line:{id}` for Contracts\PaymentRecorder,
 *   a later one (after a rejection) `statement-line:{id}:{n}`, so two
 *   attempts at once make one payment; the automatic matcher only works on
 *   lines never tried (a rejected suggestion is not suggested again).
 * - `matched_at` / `matched_by_user_id`: when the line's payment was
 *   recorded or confirmed and by whom (null: the matcher).
 *
 * No TIMESTAMP column without a default (matched_at is nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('tenant_profile_id')->nullable()->after('suggested_bill_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->unsignedSmallInteger('match_attempts')->default(0)->after('payment_id');
            $table->timestamp('matched_at')->nullable()->after('match_attempts');
            $table->foreignId('matched_by_user_id')->nullable()->after('matched_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropForeign(['tenant_profile_id']);
            $table->dropForeign(['matched_by_user_id']);
            $table->dropColumn(['tenant_profile_id', 'match_attempts', 'matched_at', 'matched_by_user_id']);
        });
    }
};
