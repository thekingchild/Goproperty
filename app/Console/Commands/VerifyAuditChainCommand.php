<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditChain;
use Illuminate\Console\Command;

/**
 * Walks the audit log's hash chain (PLATFORM_CONSOLE.md § 7) and reports
 * the first entry that was changed, removed or reordered. Exit code 1 when
 * the chain is broken.
 */
final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'goproperty:audit-verify';

    protected $description = 'Check that no audit log entry was changed, removed or reordered';

    public function handle(AuditChain $chain): int
    {
        $result = $chain->verify();

        if ($result->intact) {
            $this->components->info("The audit log is intact: {$result->checked} entries checked.");

            return self::SUCCESS;
        }

        $where = $result->brokenAtId !== null ? "at entry {$result->brokenAtId}" : 'at the end';
        $this->components->error("The audit log chain is broken {$where}: {$result->reason}. {$result->checked} entries before it are intact.");

        return self::FAILURE;
    }
}
