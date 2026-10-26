<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `scoped_records` table for Tests\Fixtures\Models\ScopedRecord, in a
 * throwaway database reached as connection "tenancy_fixture".
 */
final class TenancyFixture
{
    public static function create(): void
    {
        $database = ScratchDatabase::create();
        config(['database.connections.tenancy_fixture' => config("database.connections.{$database}")]);

        Schema::connection('tenancy_fixture')->create('scoped_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->timestamps();
        });
    }

    public static function drop(): void
    {
        DB::purge('tenancy_fixture');
        ScratchDatabase::dropAll();
    }
}
