<?php

declare(strict_types=1);

namespace App\Installer\Health;

use App\Hosting\Heartbeats;
use App\Hosting\HostingCheck;
use App\Installer\ApplicationKey;
use App\Installer\Database\OwnTables;
use App\Installer\Finishing\AdminPath;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Installer\Migrations\BatchedMigrator;
use App\Installer\Requirements\CheckStatus;
use App\Installer\Requirements\RequirementsChecker;
use App\Installer\Support\LocalHost;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * `goproperty:check-installation` (INSTALLER.md § 7): the server check, the
 * schema against the migrations, the three markers, the production defaults,
 * and that the scheduled work is running.
 */
final class InstallationHealth
{
    /** Heartbeats that must be fresh; later tasks add backups and others. */
    public const array REQUIRED_HEARTBEATS = [Heartbeats::SCHEDULER, Heartbeats::QUEUE_TICK];

    /** Cron runs every minute; allow a few missed runs. */
    public const int HEARTBEAT_MINUTES = 5;

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly RequirementsChecker $requirements,
        private readonly InstallationMarkers $markers,
        private readonly InstallationRecord $record,
        private readonly BatchedMigrator $migrator,
        private readonly OwnTables $ownTables,
        private readonly HostingCheck $hosting,
        private readonly Heartbeats $heartbeats,
    ) {}

    /** @return list<HealthCheck> */
    public function check(): array
    {
        return [
            ...$this->server(),
            $this->markers(),
            ...$this->schema(),
            ...$this->defaults(),
            ...$this->scheduledWork(),
        ];
    }

    /** @return list<HealthCheck> */
    private function server(): array
    {
        $report = $this->requirements->check();
        $checks = [];

        foreach ($report->failures() as $failure) {
            $checks[] = new HealthCheck('Server', $failure->label, CheckStatus::Fail, $failure->detail);
        }

        return $checks !== [] ? $checks : [new HealthCheck('Server', 'Server requirements', CheckStatus::Pass)];
    }

    private function markers(): HealthCheck
    {
        $lock = $this->markers->lockExists();
        $checkpoint = $this->markers->checkpoint()->read();

        try {
            $row = $this->record->stage($this->db->connection());
            $rowLabel = $row->value ?? 'missing';
        } catch (Throwable) {
            $row = null;
            $rowLabel = 'unreadable';
        }

        $checkpointLabel = match (true) {
            ! $checkpoint->exists => 'missing',
            $checkpoint->corrupt => 'cannot be verified',
            default => $checkpoint->stage->value ?? 'missing',
        };

        $detail = 'lock file: '.($lock ? 'present' : 'missing').", database row: {$rowLabel}, checkpoint: {$checkpointLabel}";

        if ($lock && $row === InstallStage::Finalized && $checkpoint->stage === InstallStage::Finalized) {
            return new HealthCheck('Installation', 'Installation markers agree', CheckStatus::Pass, $detail);
        }

        return new HealthCheck('Installation', 'Installation markers disagree or the install is unfinished', CheckStatus::Fail,
            $detail.'. See INSTALLER.md § 9.');
    }

    /** @return list<HealthCheck> */
    private function schema(): array
    {
        try {
            $pending = $this->migrator->progress()->pending;
            $connection = $this->db->connection();
            $existing = $connection->getSchemaBuilder()->getTableListing($connection->getDatabaseName(), false);
        } catch (Throwable $exception) {
            return [new HealthCheck('Database', 'Database reachable', CheckStatus::Fail, $exception->getMessage())];
        }

        $checks = [new HealthCheck('Database', 'Database reachable', CheckStatus::Pass)];

        $checks[] = $pending === []
            ? new HealthCheck('Database', 'Every migration has run', CheckStatus::Pass)
            : new HealthCheck('Database', 'Every migration has run', CheckStatus::Fail,
                count($pending).' pending ('.implode(', ', array_slice($pending, 0, 3)).'). Run php artisan migrate --force.');

        if ($pending === []) {
            $missing = array_values(array_diff($this->ownTables->expected(), $existing));

            $checks[] = $missing === []
                ? new HealthCheck('Database', 'Every GoProperty table exists', CheckStatus::Pass)
                : new HealthCheck('Database', 'Every GoProperty table exists', CheckStatus::Fail, 'Missing: '.implode(', ', $missing).'.');
        }

        return $checks;
    }

    /** @return list<HealthCheck> */
    private function defaults(): array
    {
        $environment = $this->string('app.env');
        $production = $environment === 'production';
        $checks = [];

        $checks[] = new HealthCheck('Settings', 'APP_KEY is set', ApplicationKey::configured() ? CheckStatus::Pass : CheckStatus::Fail);

        $checks[] = new HealthCheck('Settings', 'APP_ENV is production or staging',
            in_array($environment, ['production', 'staging'], true) ? CheckStatus::Pass : CheckStatus::Warn, "APP_ENV is {$environment}.");

        $debug = (bool) $this->config->get('app.debug');
        $checks[] = new HealthCheck('Settings', 'APP_DEBUG is off',
            ! $debug ? CheckStatus::Pass : ($production ? CheckStatus::Fail : CheckStatus::Warn),
            $debug ? 'Debug pages show secrets to visitors. Set APP_DEBUG=false.' : '');

        $adminPath = $this->string('app.admin_path');
        $checks[] = match (true) {
            AdminPath::isUnique($adminPath) => new HealthCheck('Settings', 'Console path is unique', CheckStatus::Pass),
            $adminPath === AdminPath::DEFAULT => new HealthCheck('Settings', 'Console path is unique', $production ? CheckStatus::Fail : CheckStatus::Warn,
                'ADMIN_PATH is still the shared default; set a unique one (PLATFORM_CONSOLE.md § 10).'),
            default => new HealthCheck('Settings', 'Console path is unique', CheckStatus::Fail, 'ADMIN_PATH breaks the rules in PLATFORM_CONSOLE.md § 10.'),
        };

        $url = parse_url($this->string('app.url'));
        $secure = is_array($url) && (($url['scheme'] ?? '') === 'https' || LocalHost::is($url['host'] ?? ''));
        $checks[] = new HealthCheck('Settings', 'APP_URL uses https', $secure ? CheckStatus::Pass : CheckStatus::Fail);

        foreach ($this->hosting->problems() as $problem) {
            $checks[] = new HealthCheck('Settings', 'Hosting settings', CheckStatus::Fail, $problem);
        }

        return $checks;
    }

    /** @return list<HealthCheck> */
    private function scheduledWork(): array
    {
        $checks = [];

        foreach (self::REQUIRED_HEARTBEATS as $name) {
            if ($name === Heartbeats::QUEUE_TICK && ! $this->hosting->tickEnabled()) {
                continue;
            }

            $label = $name === Heartbeats::SCHEDULER ? 'Cron runs the scheduler' : 'The queue tick runs';

            try {
                $at = $this->heartbeats->lastAt($name);
            } catch (Throwable) {
                $checks[] = new HealthCheck('Scheduled work', $label, CheckStatus::Fail, 'The cache cannot be read.');

                continue;
            }

            $checks[] = match (true) {
                $at === null => new HealthCheck('Scheduled work', $label, CheckStatus::Fail, 'It has never run. Add the cron line (php artisan goproperty:install prints it).'),
                $at->diffInMinutes(now(), true) > self::HEARTBEAT_MINUTES => new HealthCheck('Scheduled work', $label, CheckStatus::Fail, 'Last run '.$at->diffForHumans().'.'),
                default => new HealthCheck('Scheduled work', $label, CheckStatus::Pass, 'Last run '.$at->diffForHumans().'.'),
            };
        }

        return $checks;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }
}
