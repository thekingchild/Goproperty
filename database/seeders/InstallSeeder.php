<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data every installation needs, run by the installer after migrations
 * (INSTALLER.md § 5, step 4) and by `db:seed` in development.
 *
 * Every seeder listed here must be idempotent: safe to run again after a
 * timeout or on an existing install (upsert or firstOrCreate by a natural
 * key, never plain inserts), and must not need dev packages such as Faker,
 * because the release package has none. tests/Feature/Installer/InstallSeederTest
 * runs the list twice and compares every table.
 *
 * Added by later tasks: default plans and prices, Transaction Tiers,
 * default theme, email templates.
 */
final class InstallSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> */
    public const array SEEDERS = [
        OrganizationRolesSeeder::class,
        StaffRolesSeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::SEEDERS);
    }
}
