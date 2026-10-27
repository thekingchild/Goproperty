<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * At most one active lease per unit (ARCHITECTURE.md § 6.2: an allocation
 * is a lease), kept by the database as well as by LeaseService's unit row
 * lock: `active_unit_id` is the unit while the lease is active (and not
 * soft deleted), else null, and is unique. A stored generated column with
 * a unique index works the same on MySQL 8 and MariaDB 10.6+; null values
 * never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedBigInteger('active_unit_id')->nullable()
                ->storedAs("case when status = 'active' and deleted_at is null then unit_id end");

            $table->unique('active_unit_id', 'leases_active_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropUnique('leases_active_unit_unique');
            $table->dropColumn('active_unit_id');
        });
    }
};
