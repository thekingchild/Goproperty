<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\EnvironmentFile;
use App\Media\MediaDiskMove;
use App\Platform\Operations\Health\StorageHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Moves uploaded files (the media library) from this host's disk to the
 * bucket, or back (docs/runbooks/install.md § 6 step 7). The host's disk is
 * the default; this is the supported way to change it later (product owner,
 * 2026-11-09).
 *
 * In order: checks the target can be written to, sets MEDIA_DISK in .env
 * (and rebuilds the settings cache) so new uploads go there at once, then
 * moves the existing files (MediaDiskMove). Safe to run again: it carries on
 * where it stopped, and a second run at the same time stops at once, so it
 * can run from a one-time cron job on hosts without SSH.
 */
final class MediaMoveCommand extends Command
{
    private const string LOCK = 'goproperty:media-move';

    protected $signature = 'goproperty:media-move
        {disk=media-s3 : Where the files go: media-s3 (the bucket) or media (this host)}
        {--delete-source : Remove each old copy once its new copy is checked (also those left by earlier runs)}
        {--force : Do not ask first (for a one-time cron job on hosts without SSH)}';

    protected $description = 'Move uploaded files to the bucket (media-s3) or back to this host (media), and keep new uploads there';

    public function handle(MediaDiskMove $mover, EnvironmentFile $environment): int
    {
        $to = (string) $this->argument('disk');
        $where = $to === 'media' ? 'this host (storage/app/media)' : 'the bucket';

        try {
            $mover->checkTarget($to);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass --force to move without being asked (for example from a cron job).');

                return self::FAILURE;
            }

            if (! $this->components->confirm("Move uploaded files to {$where} and keep new uploads there?", true)) {
                $this->components->warn('Nothing was changed.');

                return self::FAILURE;
            }
        }

        $lock = Cache::lock(self::LOCK, 6 * 3600);

        if (! $lock->get()) {
            $this->components->error('Another move is running. Wait for it to finish (its output says how many files are left).');

            return self::FAILURE;
        }

        try {
            if (! $this->useForNewUploads($to, $environment)) {
                return self::FAILURE;
            }

            $result = $mover->move($to, (bool) $this->option('delete-source'), fn (string $problem) => $this->components->warn($problem));
        } finally {
            $lock->release();
        }

        Log::info('Media files moved.', ['to' => $to] + $result);
        $this->report($to, $result, $mover->counts());

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Sets MEDIA_DISK so new uploads go to the target; false when it cannot. */
    private function useForNewUploads(string $to, EnvironmentFile $environment): bool
    {
        if (config('media-library.disk_name') === $to && $environment->get('MEDIA_DISK') === $to) {
            return true;
        }

        try {
            $environment->set(['MEDIA_DISK' => $to]);
        } catch (Throwable $exception) {
            $this->components->error("MEDIA_DISK could not be written to .env ({$exception->getMessage()}). Add the line MEDIA_DISK={$to} to .env yourself, then run this again.");

            return false;
        }

        config(['media-library.disk_name' => $to]);

        if ($this->laravel->configurationIsCached()) {
            Artisan::call('config:cache');
        }

        // Long-running queue workers (a VPS) pick up the new setting.
        Artisan::call('queue:restart');
        $this->components->info("MEDIA_DISK={$to} is set in .env: new uploads go there now.");

        return true;
    }

    /**
     * @param  array{moved: int, bytes: int, waiting: int, missing: int, failed: int, cleaned: int}  $result
     * @param  array<string, int>  $counts
     */
    private function report(string $to, array $result, array $counts): void
    {
        $this->components->twoColumnDetail('Files moved', $result['moved'].' ('.StorageHealth::bytes($result['bytes']).')');
        $this->components->twoColumnDetail('Changed in the last '.MediaDiskMove::SETTLE_MINUTES.' minutes (left for the next run)', (string) $result['waiting']);
        $this->components->twoColumnDetail('Missing on the old disk (left as they are)', (string) $result['missing']);
        $this->components->twoColumnDetail('Could not be copied', (string) $result['failed']);

        if ($this->option('delete-source')) {
            $this->components->twoColumnDetail('Old copies removed from earlier runs', (string) $result['cleaned']);
        }

        foreach ($counts as $disk => $files) {
            $this->components->twoColumnDetail("Files on {$disk} now", (string) $files);
        }

        $left = $result['waiting'] + $result['failed'];

        if ($left > 0) {
            $this->components->warn("{$left} files are still to move: run this again in ".MediaDiskMove::SETTLE_MINUTES.' minutes.');
        } else {
            $this->components->info('Every file that can move has moved.');
        }

        if ($to === 'media-s3') {
            $this->components->warn('Files in the bucket are not in the nightly files backup: turn on versioning or replication for the bucket (docs/runbooks/install.md § 6 step 7).');
        }

        if (! $this->option('delete-source') && $result['moved'] > 0) {
            $this->components->info('The old copies are kept. Once the site shows its files, run this again with --delete-source to remove them.');
        }
    }
}
