<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data protection requests (P09-02; NDPA 2023 ss. 34–36, DATABASE_SCHEMA.md
 * "data_requests"): exports of an organization's or one tenant's data, and
 * erasures (a tenant anonymised, an organization purged). Written only by
 * App\Privacy\DataRequests and the jobs it runs. A platform table: kept
 * after a purge as the record that the request was carried out; it holds
 * no personal data (the tenant by id only, the reason as staff typed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('scope', 16);
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            // No foreign key: the tenant's record is anonymised or purged by the request itself.
            $table->unsignedBigInteger('tenant_profile_id')->nullable();
            $table->string('status', 24);
            $table->string('reason', 500);
            $table->foreignId('requested_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->unique()->constrained('approval_requests')->restrictOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure', 500)->nullable();
            $table->json('progress')->nullable();
            $table->json('result')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->timestamp('file_expires_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->unsignedSmallInteger('downloads')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
