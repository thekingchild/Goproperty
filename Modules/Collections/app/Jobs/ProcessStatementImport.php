<?php

declare(strict_types=1);

namespace Modules\Collections\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Statements\StatementReader;
use Throwable;

/**
 * Reads an uploaded statement's lines (Statements\StatementReader) on the
 * `imports` queue (AGENT.md § 4: imports are queued; shared hosts run it
 * from the queue tick's bulk lane), inside the statement's organization.
 * A statement is at most StatementReader::MAX_LINES transactions, read in
 * a few seconds, well within the 50-second job budget. Safe to run again:
 * a ready or failed statement is left alone, and lines already added are
 * found by their hash. When every try fails the statement is marked
 * failed (the file is kept).
 */
final class ProcessStatementImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 50;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $importId,
    ) {
        $this->onQueue('imports');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, StatementReader $reader): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization !== null) {
            $context->run($organization, fn () => $reader->process($this->importId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        app(OrganizationContext::class)->run($organization, function (): void {
            $import = BankStatementImport::query()->find($this->importId);

            if ($import instanceof BankStatementImport) {
                app(StatementReader::class)->fail($import, __('The statement could not be read. Upload it again, or ask support if it keeps failing.'));
            }
        });
    }
}
