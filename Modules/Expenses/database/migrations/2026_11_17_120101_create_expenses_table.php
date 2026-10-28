<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Expenses (P03-12; DATABASE_SCHEMA.md § 4 `expenses`): money the
 * organization spent — a plumber, diesel for the generator, a security
 * guard's pay, the tenement rate — recorded for its own books (no money
 * moves through GoProperty, AGENT.md § 3 rule 8). Written only by
 * Modules\Expenses\Recording\ExpenseBook, audited.
 *
 * - `property_id` / `unit_id`: the building (and unit) the money was spent
 *   on, read through Modules\Properties\Contracts; both empty for the
 *   organization's own costs (office rent, a salary). `property_name` and
 *   `unit_label` keep the names as recorded: an expense is the
 *   organization's history and stays with it in a management handover
 *   (P03-14), when the building itself may leave.
 * - `amount_kobo`: integer kobo (AGENT.md § 3 rule 2); `spent_on` the day
 *   it was paid (DB § 4's `spent_at`, a date).
 * - `vendor_name`: who was paid; `payment_method`, `reference` (a transfer
 *   reference, a cheque number) and `note`.
 * - `ticket_id` and `bill_id` have no foreign key and nothing writes them
 *   yet: the maintenance ticket the expense was for (Modules\Tickets, P04)
 *   and a bill that charged it on, when those flows exist.
 * - Provenance: who recorded it, who changed it last; archived = soft
 *   deleted, with who and why (never hard deleted, AGENT.md § 3 rule 7).
 *   Receipts are evidence rows of their own (`expense_receipts`).
 *
 * Indexes lead with `organization_id`; reports (P08) read expenses by
 * organization and date, per building and per category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('property_name', 150)->nullable();
            $table->string('unit_label', 100)->nullable();
            $table->string('category', 20);
            $table->string('description', 500);
            $table->unsignedBigInteger('amount_kobo');
            $table->date('spent_on');
            $table->string('vendor_name', 150)->nullable();
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'spent_on'], 'expenses_org_spent_index');
            $table->index(['organization_id', 'property_id', 'spent_on'], 'expenses_org_property_spent_index');
            $table->index(['organization_id', 'category', 'spent_on'], 'expenses_org_category_spent_index');
            $table->index(['organization_id', 'unit_id'], 'expenses_org_unit_index');
            $table->index(['organization_id', 'ticket_id'], 'expenses_org_ticket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
