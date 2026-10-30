<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The template engine also serves SMS (P05-02; PLATFORM_CONSOLE.md § 5
 * "Delivery": "The same template engine later serves SMS and WhatsApp
 * templates"). `email` for the templates P05-04 seeded; `sms` for plain
 * one-page texts (subject empty, no Markdown, at most 160 GSM characters
 * with the longest values; App\Mail\Templates\EmailTemplates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('channel', 10)->default('email')->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
