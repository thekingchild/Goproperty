<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Models\PlatformStaff;
use App\Platform\Operations\Queues\FailedJobs;
use App\Platform\Operations\Queues\QueueHealth;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Queues (PLATFORM_CONSOLE.md § 2): depth and wait per queue, failed jobs in
 * the last 24 hours and in all, and the latest failures with Retry and
 * Forget for `queues.manage` (checked again by FailedJobs, audited).
 */
final class QueuesWidget extends OperationsWidget
{
    protected static ?int $sort = 3;

    protected string $view = 'console.operations.queues';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::QueuesManage);
    }

    public function retryFailedJob(string $uuid): void
    {
        $this->act(fn (PlatformStaff $staff) => app(FailedJobs::class)->retry($staff, $uuid), 'Put back on its queue');
    }

    public function forgetFailedJob(string $uuid): void
    {
        $this->act(fn (PlatformStaff $staff) => app(FailedJobs::class)->forget($staff, $uuid), 'Failed job forgotten');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(QueueHealth::class);
        $now = CarbonImmutable::now();

        return [
            'now' => $now,
            'driver' => $health->driver(),
            'queues' => $health->figures($now),
            'failedDay' => $health->failedCount($now->subDay()),
            'failedAll' => $health->failedCount(),
            'failures' => $health->recentFailures(),
            'canManage' => self::allowed(P::QueuesManage),
        ];
    }

    /** @param  callable(PlatformStaff): void  $action */
    private function act(callable $action, string $done): void
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof PlatformStaff && self::allowed(P::QueuesManage), 403);

        try {
            $action($staff);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title($exception->getMessage())->warning()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
    }
}
