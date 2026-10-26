<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development data (`php artisan migrate --seed`). Production installs run
 * InstallSeeder only, through the installer.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(InstallationMarkers $markers, InstallationRecord $record): void
    {
        $this->call(InstallSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Without both markers every page shows "being set up"
        // (INSTALLER.md § 3). Only a local copy is marked here; real
        // installs are finalized by the installer.
        if (app()->isLocal()) {
            $markers->writeLock('dev');
            $record->record(DB::connection(), InstallStage::Finalized, 'dev');
        }
    }
}
