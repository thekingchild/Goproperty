<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phone verification without SMS (DATABASE_SCHEMA.md § 1 `users`,
 * ONBOARDING_COMPLIANCE.md § 2, ARCHITECTURE.md § 6.7): a phone number is
 * verified when its owner opens an invitation or sign-in link that reached
 * that number by WhatsApp (`whatsapp_link`), or when the agency confirms it
 * in person (`agency_confirmed`, with who confirmed it). Built in P03-01b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('phone_verification_method', 20)->nullable()->after('phone_verified_at');
            $table->foreignId('phone_confirmed_by_user_id')->nullable()->after('phone_verification_method')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('phone_confirmed_by_user_id');
            $table->dropColumn(['phone_verified_at', 'phone_verification_method']);
        });
    }
};
