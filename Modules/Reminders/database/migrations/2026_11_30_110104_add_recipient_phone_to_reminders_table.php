<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SMS reminders (P05-02): the verified number an `sms` row goes to, as
 * planned (E.164). Null for the other channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->string('recipient_phone', 20)->nullable()->after('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn('recipient_phone');
        });
    }
};
