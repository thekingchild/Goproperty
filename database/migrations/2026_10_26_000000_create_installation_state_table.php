<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row (id 1): the database marker of INSTALLER.md § 3. Status moves
 * configured → migrating → owner_ready → finalized through the installer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('status', 20);
            $table->string('version', 40)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_state');
    }
};
