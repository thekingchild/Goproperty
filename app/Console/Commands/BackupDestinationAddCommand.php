<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupDestination;
use App\Backup\DestinationCheck;
use Illuminate\Console\Command;

/**
 * Adds a backup destination from the command line (the console screen
 * comes in P06-10). Secrets come from files or a hidden prompt, never
 * from the command line, where they would land in the shell history.
 */
final class BackupDestinationAddCommand extends Command
{
    protected $signature = 'goproperty:backup-destination:add
        {name : A name, e.g. "Cloudflare R2"}
        {driver : s3 or google_drive}
        {--for=both : db, files or both}
        {--bucket= : S3: bucket}
        {--endpoint= : S3: endpoint URL (R2, Spaces, Wasabi, B2…)}
        {--region=auto : S3: region}
        {--key= : S3: access key id}
        {--secret-file= : S3: file holding the secret access key (asked for when missing)}
        {--path-style : S3: use path-style URLs}
        {--root=goproperty-backups : S3: folder inside the bucket}
        {--service-account-file= : Google Drive: the JSON key of the service account}
        {--shared-drive-id= : Google Drive: the Shared Drive the service account is a member of}
        {--folder-id= : Google Drive: a folder inside it (default: the drive\'s top level)}
        {--no-check : Save without writing a test file}';

    protected $description = 'Add an S3-compatible or Google Drive backup destination';

    public function handle(DestinationCheck $check): int
    {
        $driver = (string) $this->argument('driver');
        $for = (string) $this->option('for');

        if (! in_array($driver, ['s3', 'google_drive'], true) || ! in_array($for, ['db', 'files', 'both'], true)) {
            $this->components->error('The driver is s3 or google_drive, and --for is db, files or both.');

            return self::INVALID;
        }

        $config = $driver === 's3' ? $this->s3() : $this->googleDrive();

        if ($config === null) {
            return self::INVALID;
        }

        $destination = BackupDestination::query()->create([
            'name' => (string) $this->argument('name'),
            'driver' => $driver,
            'config' => $config,
            'applies_to' => $for,
        ]);

        if (! $this->option('no-check')) {
            $problem = $check->problem($destination);

            if ($problem !== null) {
                $destination->update(['active' => false]);
                $this->components->error("Saved as #{$destination->id} but switched off: the test file could not be written. {$problem}");

                return self::FAILURE;
            }
        }

        $this->components->info("Backup destination #{$destination->id} \"{$destination->name}\" saved".($this->option('no-check') ? '.' : ' and checked.'));

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function s3(): ?array
    {
        $file = $this->option('secret-file');
        $secret = is_string($file) && $file !== '' ? trim((string) @file_get_contents($file)) : (string) $this->secret('Secret access key');

        foreach (['bucket' => $this->option('bucket'), 'key' => $this->option('key'), 'secret' => $secret] as $field => $value) {
            if (! is_string($value) || $value === '') {
                $this->components->error("An S3 destination needs --{$field}".($field === 'secret' ? '-file' : '').'.');

                return null;
            }
        }

        return [
            'bucket' => $this->option('bucket'),
            'endpoint' => $this->option('endpoint') ?: null,
            'region' => $this->option('region') ?: 'auto',
            'key' => $this->option('key'),
            'secret' => $secret,
            'use_path_style_endpoint' => (bool) $this->option('path-style'),
            'root' => trim((string) $this->option('root'), '/') ?: 'goproperty-backups',
        ];
    }

    /** @return array<string, mixed>|null */
    private function googleDrive(): ?array
    {
        $file = (string) $this->option('service-account-file');
        $account = $file !== '' ? json_decode((string) @file_get_contents($file), true) : null;
        $drive = (string) $this->option('shared-drive-id');

        if (! is_array($account) || ! isset($account['client_email'], $account['private_key'])) {
            $this->components->error('--service-account-file must be the JSON key Google issued for the service account.');

            return null;
        }

        if ($drive === '') {
            $this->components->error('--shared-drive-id is required: service accounts have no storage of their own.');

            return null;
        }

        return [
            'service_account' => $account,
            'shared_drive_id' => $drive,
            'folder_id' => $this->option('folder-id') ?: null,
        ];
    }
}
