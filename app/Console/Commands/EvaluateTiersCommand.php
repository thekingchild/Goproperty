<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Compliance\Monitoring\TierMonitor;
use Illuminate\Console\Command;

/**
 * The daily Transaction Tier run by hand (P06-09; ARCHITECTURE.md § 11):
 * the scheduler runs the same TierMonitor::daily() at 03:30 through
 * Schedule::call (no shell on shared hosting). Safe to re-run: the day's
 * snapshot is replaced and no flag is raised twice.
 */
final class EvaluateTiersCommand extends Command
{
    protected $signature = 'compliance:evaluate-tiers';

    protected $description = "Measure every organization's collections against its Transaction Tier: snapshots, flags, grace and restriction";

    public function handle(TierMonitor $monitor): int
    {
        $counts = $monitor->daily();

        $this->components->info("Measured {$counts['organizations']} organizations; {$counts['flags']} new flags.");

        return self::SUCCESS;
    }
}
