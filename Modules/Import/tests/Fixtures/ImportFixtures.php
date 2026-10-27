<?php

declare(strict_types=1);

namespace Modules\Import\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Import\Contracts\ImportTargets;

/**
 * The fixture targets' tables, as TEMPORARY tables: MySQL and MariaDB
 * create and drop those without committing the test's transaction
 * (RefreshDatabase), and they vanish with the connection. The real schema
 * stays GoProperty's own.
 */
final class ImportFixtures
{
    public const string CONTACTS = 'import_fixture_contacts';

    public const string NOTES = 'import_fixture_notes';

    public static function install(): void
    {
        self::drop();

        // Raw SQL: indexes must be part of CREATE TEMPORARY TABLE (an ALTER
        // TABLE afterwards would commit the test's transaction).
        DB::statement('CREATE TEMPORARY TABLE '.self::CONTACTS.' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            joined_on DATE NULL,
            balance_kobo BIGINT NULL,
            category VARCHAR(16) NULL,
            external_ref VARCHAR(100) NULL,
            import_batch_id BIGINT UNSIGNED NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            deleted_at TIMESTAMP NULL,
            INDEX contacts_phone (organization_id, phone),
            INDEX contacts_ref (organization_id, external_ref),
            INDEX contacts_batch (organization_id, import_batch_id)
        )');

        Schema::create(self::NOTES, function (Blueprint $table) {
            $table->temporary();
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('note');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->softDeletes();
        });

        $targets = app(ImportTargets::class);
        $targets->register(ContactsTarget::class);
        $targets->register(NotesTarget::class);
        ContactsTarget::$failOnName = null;
    }

    public static function drop(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS '.self::CONTACTS);
        DB::statement('DROP TEMPORARY TABLE IF EXISTS '.self::NOTES);
    }
}
