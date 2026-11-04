@php
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;

    $cell = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
    $fresh = $scheduler !== null && $scheduler->diffInSeconds($now, true) <= $window * 60;
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Scheduler" description="Cron runs the scheduler every minute; it counts as stopped after {{ $window }} minutes without a heartbeat.">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.875rem;margin-bottom:.75rem">
            <span>Scheduler:
                <x-filament::badge :color="$scheduler === null ? 'gray' : ($fresh ? 'success' : 'danger')" style="display:inline-flex">
                    <span data-figure="scheduler.state">{{ $scheduler === null ? 'Never ran' : ($fresh ? 'Running' : 'Stopped') }}</span>
                </x-filament::badge>
                last run <span data-figure="scheduler.last">{{ W::lagos($scheduler) ?? 'never' }}</span>
            </span>
            <span>Queue tick:
                @if ($tickEnabled)
                    last run <span data-figure="tick.last">{{ W::lagos($tickAt) ?? 'never' }}</span>
                    @if (is_array($tick) && is_string($tick['stopped_by'] ?? null))
                        <span style="opacity:.7">(stopped by {{ $tick['stopped_by'] }})</span>
                    @endif
                @else
                    <span data-figure="tick.last">off (a worker or Horizon runs the queue)</span>
                @endif
            </span>
        </div>

        @if ($tasks === [])
            <p style="font-size:.875rem">The task list appears after the scheduler's next run.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead><tr><th style="{{ $cell }}">Task</th><th style="{{ $cell }}">When (cron, {{ config('app.timezone') }})</th><th style="{{ $cell }}">Last run</th><th style="{{ $cell }}">Result</th><th style="{{ $cell }}">Next run</th></tr></thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)" data-task="{{ $task->name }}">
                                <td style="{{ $cell }}">{{ $task->name }}</td>
                                <td style="{{ $cell }};white-space:nowrap"><code>{{ $task->expression }}</code></td>
                                <td style="{{ $cell }};white-space:nowrap">{{ W::lagos($task->lastRunAt) ?? '—' }}</td>
                                <td style="{{ $cell }}" data-figure="task.{{ $task->name }}">
                                    @if ($task->missedRunAt !== null)
                                        <x-filament::badge color="danger" style="display:inline-flex">Missed {{ W::lagos($task->missedRunAt) }}</x-filament::badge>
                                    @elseif ($task->failed())
                                        <x-filament::badge color="danger" style="display:inline-flex">{{ $task->resultLabel() }}</x-filament::badge>
                                    @else
                                        {{ $task->resultLabel() }}
                                    @endif
                                </td>
                                <td style="{{ $cell }};white-space:nowrap">{{ W::lagos($task->nextRunAt) ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($cronLine !== null)
            <p style="font-size:.8125rem;margin:.75rem 0 0">The cron line for this server (cPanel → Cron Jobs, every minute):</p>
            <pre style="font-size:.75rem;white-space:pre-wrap;word-break:break-all;margin:.25rem 0 0" data-figure="scheduler.cron">{{ $cronLine }}</pre>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
