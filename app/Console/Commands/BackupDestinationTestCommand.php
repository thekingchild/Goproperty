<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupDestination;
use App\Backup\DestinationCheck;
use Illuminate\Console\Command;

/** Writes, reads and deletes a test file at a destination; can switch it off or on. */
final class BackupDestinationTestCommand extends Command
{
    protected $signature = 'goproperty:backup-destination:test {id} {--disable : Switch the destination off instead} {--enable : Switch it on after a passing check}';

    protected $description = 'Check a backup destination, or switch it off or on';

    public function handle(DestinationCheck $check): int
    {
        $destination = BackupDestination::query()->find($this->argument('id'));

        if ($destination === null) {
            $this->components->error('There is no such backup destination.');

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            $destination->update(['active' => false]);
            $this->components->info("\"{$destination->name}\" is switched off.");

            return self::SUCCESS;
        }

        $problem = $check->problem($destination);

        if ($problem !== null) {
            $this->components->error("\"{$destination->name}\" does not work: {$problem}");

            return self::FAILURE;
        }

        if ($this->option('enable')) {
            $destination->update(['active' => true]);
        }

        $this->components->info("\"{$destination->name}\" works".($destination->active ? '.' : ', but is switched off (use --enable).'));

        return self::SUCCESS;
    }
}
