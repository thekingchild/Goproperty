<?php

declare(strict_types=1);

use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Models\PlatformStaff;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/*
 * Helpers for the upgrade tests (P09-06): a release number in a VERSION file
 * of the test's own, pending migrations from a folder of the test's own, and
 * the console's authenticator code.
 *
 * The pending migrations only write rows (no table changes), so they run
 * inside the test's RefreshDatabase transaction on MySQL and MariaDB alike.
 */

/** This code is release $version; the database last recorded $database. */
function upgradeRelease(string $version, ?string $database = null): void
{
    $file = sys_get_temp_dir().'/goproperty-version-'.Str::random(10);
    File::put($file, $version."\n");
    upgradeFile($file);
    config(['installer.paths.version' => $file]);

    if ($database !== null) {
        app(InstallationRecord::class)->record(DB::connection(), InstallStage::Finalized, $database);
    }
}

/**
 * Adds $count pending migrations that each write one settings row
 * (`upgrade.test.N`); with $broken, the one at that position fails until a
 * settings row `upgrade.test.fixed` exists.
 *
 * @return list<string> their names, in run order
 */
function pendingMigrations(int $count, ?int $broken = null): array
{
    $dir = sys_get_temp_dir().'/goproperty-upgrade-migrations-'.Str::random(10);
    File::ensureDirectoryExists($dir);
    upgradeFile($dir);
    $names = [];

    for ($i = 1; $i <= $count; $i++) {
        $name = sprintf('2099_02_01_%06d_upgrade_test_%d', $i, $i);
        $body = $i === $broken
            // Fails until the cause is fixed: a settings row `upgrade.test.fixed`.
            ? "if (! DB::table('settings')->where('key', 'upgrade.test.fixed')->exists()) { DB::statement('select * from table_that_does_not_exist'); }"
            : "DB::table('settings')->insert(['scope_type' => 'global', 'scope_id' => 0, 'key' => 'upgrade.test.{$i}', 'value' => json_encode({$i}), 'created_at' => now(), 'updated_at' => now()]);";
        File::put("{$dir}/{$name}.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration {\n    public function up(): void { {$body} }\n    public function down(): void { DB::table('settings')->where('key', 'upgrade.test.{$i}')->delete(); }\n};\n");
        $names[] = $name;
    }

    app(Migrator::class)->path($dir);

    return $names;
}

/** Files and folders made for this test, removed by removeUpgradeFiles(). */
function upgradeFile(?string $path = null, bool $forget = false): array
{
    static $paths = [];

    if ($path !== null) {
        $paths[] = $path;
    }

    $all = $paths;

    if ($forget) {
        $paths = [];
    }

    return $all;
}

function removeUpgradeFiles(): void
{
    foreach (upgradeFile(forget: true) as $path) {
        is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
    }
}

function upgradeCode(PlatformStaff $staff): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret);
}

/** @return array<string, string> data-figure => text */
function upgradeFigures(string $html): array
{
    preg_match_all('/data-figure="([^"]+)"[^>]*>(.*?)<\/(?:td|span|strong|ul|div|p|b)>/s', $html, $matches, PREG_SET_ORDER);
    $figures = [];

    foreach ($matches as [, $key, $value]) {
        $figures[$key] ??= trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($value))));
    }

    return $figures;
}
