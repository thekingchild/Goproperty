<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every saved wording of an email template (P05-04; PLATFORM_CONSOLE.md
 * § 5): each edit adds a version, a rollback adds a copy of the version it
 * goes back to (`restored_from_version`), so the history is never
 * rewritten. Rows are never changed or deleted.
 *
 * - `body_markdown`: Markdown with whitelisted `{{ placeholders }}`, never
 *   Blade (AGENT.md § 3 rule 10).
 * - `action_label`: the button's words; the link comes from the code that
 *   sends the message, and the button shows only when it gives one.
 * - `created_by_staff_id`: null for the version seeded from code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject', 200);
            $table->text('body_markdown');
            $table->string('action_label', 60)->nullable();
            $table->unsignedInteger('restored_from_version')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['email_template_id', 'version'], 'email_template_versions_template_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_versions');
    }
};
