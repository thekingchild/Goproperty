<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A plan's priced terms from a date (P06-01a; BILLING_PAYMENTS.md § 1.3):
 * base price a month, units included, the price of each extra unit (null =
 * custom, Enterprise), in kobo. Its features and limits are `plan_feature`
 * rows. The version in effect on a day is the latest, not cancelled, with
 * `effective_from` (a Lagos date) on or before it. A version of a plan that
 * customers are on takes effect at least 30 days after `created_at`, the day
 * they were told (`notice_given_at`); one for a plan nobody is on may start
 * at once (notice_given_at null). Never changed once in effect, never
 * deleted; a scheduled one may be cancelled before its date. Written only by
 * App\Platform\Plans\PlanEditor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('base_price_kobo');
            $table->unsignedInteger('included_units');
            $table->unsignedBigInteger('unit_price_kobo')->nullable();
            $table->date('effective_from');
            $table->timestamp('notice_given_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'version']);
            $table->index(['plan_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_versions');
    }
};
