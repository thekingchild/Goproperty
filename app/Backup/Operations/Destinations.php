<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Backup\BackupDestination;
use App\Backup\DestinationCheck;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use Illuminate\Support\Facades\DB;

/**
 * Backup destinations from the console (P06-10; PLATFORM_CONSOLE.md § 4
 * "Destinations"), `backups.destinations`: add an S3-compatible bucket or a
 * Google Drive Shared Drive folder, change it, check it, switch it off or
 * on. Credentials are stored encrypted and never shown again: a blank
 * credential field on a change keeps the stored one. A destination is never
 * deleted, because its runs point at it; it is switched off instead. A
 * local folder is offered only outside production (drills). Audited (log
 * `backups`) without credentials.
 */
final readonly class Destinations
{
    /**
     * The fields of each driver: [name, label, secret?, required?].
     *
     * @var array<string, list<array{string, string, bool, bool}>>
     */
    public const array FIELDS = [
        's3' => [
            ['bucket', 'Bucket', false, true],
            ['endpoint', 'Endpoint URL', false, false],
            ['region', 'Region', false, false],
            ['root', 'Folder inside the bucket', false, false],
            ['key', 'Access key ID', true, true],
            ['secret', 'Secret access key', true, true],
        ],
        'google_drive' => [
            ['shared_drive_id', 'Shared Drive ID', false, true],
            ['folder_id', 'Folder ID (optional)', false, false],
            ['service_account', 'Service account key (JSON)', true, true],
        ],
        'local' => [
            ['root', 'Folder on this server', false, true],
        ],
    ];

    public function __construct(
        private DestinationCheck $check,
        private BackupAudit $audit,
    ) {}

    /** @return array<string, string> */
    public static function drivers(): array
    {
        $drivers = ['s3' => 'S3-compatible (R2, Spaces, Wasabi, B2)', 'google_drive' => 'Google Drive (Shared Drive)'];

        return app()->isProduction() ? $drivers : $drivers + ['local' => 'Folder on this server (drills only)'];
    }

    /** @param  array<string, mixed>  $input  name, driver, applies_to and the driver's fields */
    public function create(PlatformStaff $staff, array $input): BackupDestination
    {
        $this->authorize($staff);
        $driver = (string) ($input['driver'] ?? '');

        if (! array_key_exists($driver, self::drivers())) {
            throw new BackupRefused('Choose where the backups go.');
        }

        $destination = BackupDestination::query()->create([
            'name' => $this->name($input),
            'driver' => $driver,
            'applies_to' => $this->appliesTo($input),
            'config' => $this->config($driver, $input, []),
            'active' => true,
        ]);

        $this->audit->record('backup_destination.created', $destination, $staff, "Backup destination \"{$destination->name}\" added", $this->described($destination));

        return $destination;
    }

    /** @param  array<string, mixed>  $input */
    public function update(PlatformStaff $staff, BackupDestination $destination, array $input): BackupDestination
    {
        $this->authorize($staff);

        return DB::transaction(function () use ($staff, $destination, $input): BackupDestination {
            $locked = BackupDestination::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $before = $this->described($locked);
            $secretsChanged = array_values(array_filter(
                self::FIELDS[$locked->driver] ?? [],
                fn (array $field): bool => $field[2] && trim((string) ($input[$field[0]] ?? '')) !== '',
            ));

            $locked->forceFill([
                'name' => $this->name($input),
                'applies_to' => $this->appliesTo($input),
                'config' => $this->config($locked->driver, $input, $locked->config),
                // A changed destination has not been checked yet.
                'checked_at' => null,
                'check_problem' => null,
            ])->save();

            $this->audit->record('backup_destination.updated', $locked, $staff, "Backup destination \"{$locked->name}\" changed", [
                'before' => $before,
                'after' => $this->described($locked),
                'credentials_replaced' => array_map(fn (array $field): string => $field[0], $secretsChanged),
            ]);

            $destination->setRawAttributes($locked->getAttributes(), true);

            return $destination;
        });
    }

    /** Writes, reads back and deletes a small file; remembers the outcome. */
    public function check(PlatformStaff $staff, BackupDestination $destination): ?string
    {
        $this->authorize($staff);
        $problem = $this->check->problem($destination);
        $destination->forceFill(['checked_at' => now(), 'check_problem' => $problem !== null ? mb_substr($problem, 0, 500) : null])->save();
        $this->audit->record('backup_destination.checked', $destination, $staff, "Backup destination \"{$destination->name}\" checked: ".($problem === null ? 'it works' : 'it failed'), [
            'ok' => $problem === null,
            'problem' => $problem !== null ? mb_substr($problem, 0, 500) : null,
        ]);

        return $problem;
    }

    public function switch(PlatformStaff $staff, BackupDestination $destination, bool $active): BackupDestination
    {
        $this->authorize($staff);

        if ($destination->active === $active) {
            return $destination;
        }

        $destination->forceFill(['active' => $active])->save();
        $this->audit->record($active ? 'backup_destination.switched_on' : 'backup_destination.switched_off', $destination, $staff, "Backup destination \"{$destination->name}\" switched ".($active ? 'on' : 'off'));

        return $destination;
    }

    /**
     * What may be shown of a destination: never a credential.
     *
     * @return array<string, mixed>
     */
    public function described(BackupDestination $destination): array
    {
        $shown = ['name' => $destination->name, 'driver' => $destination->driver, 'applies_to' => $destination->applies_to, 'active' => $destination->active];

        foreach (self::FIELDS[$destination->driver] ?? [] as [$field, , $secret]) {
            $shown[$field] = $secret ? (($destination->config[$field] ?? '') !== '' ? 'set' : 'not set') : ($destination->config[$field] ?? null);
        }

        return $shown;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function config(string $driver, array $input, array $stored): array
    {
        $config = $this->fieldValues($driver, $input, $stored);

        if ($driver === 's3') {
            $endpoint = $config['endpoint'] ?? null;

            if (is_string($endpoint) && filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new BackupRefused('The endpoint is a URL, e.g. https://<account>.r2.cloudflarestorage.com.');
            }

            $root = $config['root'] ?? '';
            $config['root'] = trim(is_string($root) ? $root : '', '/') ?: 'goproperty-backups';
            $config['region'] ??= 'auto';
            $config['use_path_style_endpoint'] = (bool) ($input['use_path_style_endpoint'] ?? $stored['use_path_style_endpoint'] ?? false);
        }

        if ($driver === 'google_drive') {
            $account = $config['service_account'] ?? null;
            $key = is_array($account) ? $account : json_decode(is_string($account) ? $account : '', true);

            if (! is_array($key) || ! isset($key['client_email'], $key['private_key'])) {
                throw new BackupRefused('The service account key is the JSON file Google gives for a service account.');
            }

            $config['service_account'] = $key;
        }

        return $config;
    }

    /**
     * The driver's fields from the form; a blank credential keeps the stored
     * one (the Google key is kept as decoded JSON).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function fieldValues(string $driver, array $input, array $stored): array
    {
        $values = [];

        foreach (self::FIELDS[$driver] as [$field, $label, $secret, $required]) {
            $value = trim((string) ($input[$field] ?? ''));

            if ($secret && $value === '' && isset($stored[$field]) && $stored[$field] !== '') {
                $values[$field] = $stored[$field];

                continue;
            }

            if ($required && $value === '') {
                throw new BackupRefused("{$label} is needed.");
            }

            if ($value !== '') {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /** @param  array<string, mixed>  $input */
    private function name(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new BackupRefused('Give the destination a name of up to 100 characters.');
        }

        return $name;
    }

    /** @param  array<string, mixed>  $input */
    private function appliesTo(array $input): string
    {
        $appliesTo = (string) ($input['applies_to'] ?? 'both');

        if (! in_array($appliesTo, ['db', 'files', 'both'], true)) {
            throw new BackupRefused('Choose which backups go there.');
        }

        return $appliesTo;
    }

    private function authorize(PlatformStaff $staff): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::BackupDestinations)) {
            throw new BackupRefused('You do not have the permission for this.');
        }
    }
}
