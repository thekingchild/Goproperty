<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Templates\TemplateSync;
use Throwable;

/**
 * The daily template sync (ARCHITECTURE.md § 6.7, `whatsapp-templates-sync`,
 * Schedule::job daily on `notifications`): every organization with a
 * WhatsApp connection that is not disconnected, each inside its own
 * context. The organizations are found with one unscoped read of
 * `organization_whatsapp_connections.organization_id` (no scope removed).
 * Stops after 40 seconds and queues itself again from the next
 * organization (shared-hosting workers are time-limited, AGENT.md § 4); a
 * failure in one organization is reported and the others go on.
 */
final class SyncWhatsAppTemplates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const int BUDGET_SECONDS = 40;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public readonly int $afterOrganizationId = 0)
    {
        $this->onQueue('notifications');
    }

    public function handle(OrganizationContext $context, TemplateSync $sync): void
    {
        $started = microtime(true);
        $ids = DB::table('organization_whatsapp_connections')
            ->where('status', '!=', 'disconnected')
            ->where('organization_id', '>', $this->afterOrganizationId)
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($ids as $id) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                self::dispatch($id - 1);

                return;
            }

            $organization = Organization::query()->find($id);

            if ($organization === null) {
                continue;
            }

            try {
                $context->run($organization, static function () use ($sync): void {
                    $connection = WhatsAppConnection::query()->first();

                    if ($connection instanceof WhatsAppConnection && $connection->connected()) {
                        $sync->sync($connection);
                    }
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
