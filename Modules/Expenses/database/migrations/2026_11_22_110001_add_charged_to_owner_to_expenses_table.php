<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P08-05 (owner statements): whether the agency charges the expense to the
 * building's owner. Only charged expenses are deducted from what the owner
 * is due (DATABASE_SCHEMA.md § 4 "expenses"). Off for every existing
 * expense.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('charged_to_owner')->default(false)->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('charged_to_owner');
        });
    }
};
