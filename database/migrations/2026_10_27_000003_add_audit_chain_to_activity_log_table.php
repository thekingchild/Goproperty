<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The audit log becomes append-only with a hash chain (PLATFORM_CONSOLE.md
 * § 7, DATABASE_SCHEMA.md § 8): who impersonated, IP, user agent, reason,
 * the previous entry's hash and this entry's hash. audit_chain_head holds
 * the newest hash and is the lock appends take. Entries written before
 * this migration are sealed into the chain in id order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonator_staff_id')->nullable()->after('causer_id');
            $table->string('ip', 45)->nullable()->after('properties');
            $table->string('user_agent', 255)->nullable()->after('ip');
            $table->text('reason')->nullable()->after('user_agent');
            $table->char('prev_hash', 64)->nullable()->after('reason');
            $table->char('hash', 64)->nullable()->unique()->after('prev_hash');
            $table->index('created_at');
        });

        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('audit_chain_head')->insert(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);

        if (DB::table('activity_log')->exists()) {
            app(AuditChain::class)->sealUnchained();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropUnique(['hash']);
            $table->dropColumn(['impersonator_staff_id', 'ip', 'user_agent', 'reason', 'prev_hash', 'hash']);
        });
    }
};
