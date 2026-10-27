<?php

declare(strict_types=1);

namespace Modules\Collections\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Collections\Matching\LineMatches;
use Modules\Collections\Models\BankStatementLine;

/**
 * Statement matching (P03-07b): for each credit of the statement (or of
 * the whole organization) nobody has matched or tried yet, Matching\
 * LineMatches::matchAutomatically() records a payment waiting for
 * confirmation when MatchFinder finds its bill. Queued on `billing` (it
 * records payments; AGENT.md § 4) after the statement is read and when an
 * owner or administrator asks again, inside the organization's context.
 *
 * Shared hosts give a job about 50 seconds: one run takes at most CHUNK
 * lines and stops after BUDGET_SECONDS, then queues itself for the lines
 * after the last one it looked at. Safe to run again or twice at once:
 * a line is matched under its row lock and its payment's idempotency key
 * (statement-line:{id}), and lines tried before are skipped.
 */
final class MatchStatementLines implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int CHUNK = 250;

    public const int BUDGET_SECONDS = 35;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 50;

    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $importId = null,
        public readonly int $afterLineId = 0,
    ) {
        $this->onQueue('billing');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, LineMatches $matches): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($matches): void {
            $started = microtime(true);
            $ids = BankStatementLine::query()
                ->offeredForMatching()
                ->where('match_attempts', 0)
                ->when($this->importId !== null, fn ($query) => $query->where('bank_statement_import_id', $this->importId))
                ->where('id', '>', $this->afterLineId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();
            $last = null;

            foreach ($ids as $id) {
                $matches->matchAutomatically((int) $id);
                $last = (int) $id;

                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    break;
                }
            }

            if ($last !== null && ($last !== (int) end($ids) || count($ids) === self::CHUNK)) {
                self::dispatch($this->organizationId, $this->importId, $last);
            }
        });
    }
}
