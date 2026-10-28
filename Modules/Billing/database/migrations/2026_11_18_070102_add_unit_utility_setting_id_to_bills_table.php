<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Utility bills (P03-06b; ARCHITECTURE.md § 6.4 "How utility bills are
 * built"): `unit_utility_setting_id` names the unit's utility a `utility`
 * bill is for (null on every other bill), and `(unit_utility_setting_id,
 * period_start)` is the natural key of a generated utility bill: unique,
 * so a re-run (or two runs at once) of bill generation never bills a
 * utility's period twice (AGENT.md § 3 rule 6). NULLs never collide in a
 * unique index (MySQL and MariaDB), so other bills are not constrained.
 *
 * Utility bills leave `lease_id` empty: rent's natural key
 * `(lease_id, type, period_start)` would make two utilities of one lease
 * with the same period start collide. The lease's number is kept with the
 * parties (`details`).
 *
 * History: the bill stays with the organization that issued it; the
 * setting it points at may move with its building (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('unit_utility_setting_id')->nullable()->after('unit_id')->constrained('unit_utility_settings')->restrictOnDelete();
            $table->unique(['unit_utility_setting_id', 'period_start'], 'bills_utility_setting_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['unit_utility_setting_id']);
            $table->dropUnique('bills_utility_setting_period_unique');
            $table->dropColumn('unit_utility_setting_id');
        });
    }
};
