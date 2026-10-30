<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoProperty's email templates (P05-04; PLATFORM_CONSOLE.md § 5,
 * DATABASE_SCHEMA.md "email_templates / email_template_versions"): one row
 * per message, written by `content-manager` in the console, never by
 * agencies. The wording is in `email_template_versions`; this row points at
 * the live one.
 *
 * - `key`: the message, e.g. `reminder.rent_due`; code sends by key.
 * - `allowed_placeholders`: the whitelist, from the code that sends it
 *   (App\Mail\Templates\TemplateCatalog); nothing else is ever filled in.
 * - `current_version_id`: no foreign key, since the versions table points
 *   back here; App\Mail\Templates\EmailTemplates keeps it right.
 *
 * Platform table: no organization_id (templates are GoProperty's, the
 * agency's branding comes in through the layout and the placeholders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('category', 20);
            $table->json('allowed_placeholders');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['category', 'key'], 'email_templates_category_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
