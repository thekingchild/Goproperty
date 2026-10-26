<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Models\User;
use App\Platform\Staff\FirstSuperAdmin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development data (`php artisan migrate --seed`). Production installs run
 * InstallSeeder only, through the installer.
 *
 * Demo sign-ins, local copies only:
 * - customer: test@example.com / password (owner of Demo Realty: /o/demo-realty)
 * - staff:    staff@example.com / password (super-admin; console at /{ADMIN_PATH})
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(InstallationMarkers $markers, InstallationRecord $record): void
    {
        $this->call(InstallSeeder::class);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Demo Realty',
            'slug' => 'demo-realty',
        ]);
        $user->joinOrganization($organization, OrganizationRole::Owner);

        if (app()->isLocal()) {
            $staff = PlatformStaff::query()->create([
                'name' => 'Demo Staff',
                'email' => 'staff@example.com',
                'password' => 'password',
                'status' => StaffStatus::Active,
            ]);
            app(FirstSuperAdmin::class)->grant($staff);

            // Without both markers every page shows "being set up"
            // (INSTALLER.md § 3). Real installs are finalized by the installer.
            $markers->writeLock('dev');
            $record->record(DB::connection(), InstallStage::Finalized, 'dev');
        }
    }
}
