@php
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;

    $cell = 'padding:.4rem .5rem;text-align:right;white-space:nowrap';
    $label = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
    $minutes = fn (?int $seconds): string => $seconds === null ? '—' : ($seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min');
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Queues" description="Waiting = due and not started; the wait is the oldest waiting job's. Driver: {{ $driver }}.">
        @if ($driver === 'sync')
            <p style="font-size:.875rem">The queue runs jobs at once (sync): nothing waits.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead>
                        <tr><th style="{{ $label }}">Queue</th><th style="{{ $cell }}">Waiting</th><th style="{{ $cell }}">Oldest wait</th><th style="{{ $cell }}">Delayed</th><th style="{{ $cell }}">Running</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($queues as $queue)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)">
                                <th style="{{ $label }}">{{ $queue->queue }}</th>
                                <td style="{{ $cell }}" data-figure="queue.{{ $queue->queue }}.waiting">{{ $queue->waiting ?? '—' }}</td>
                                <td style="{{ $cell }}" data-figure="queue.{{ $queue->queue }}.wait">{{ $minutes($queue->waitSeconds($now)) }}</td>
                                <td style="{{ $cell }}" data-figure="queue.{{ $queue->queue }}.delayed">{{ $queue->delayed ?? '—' }}</td>
                                <td style="{{ $cell }}" data-figure="queue.{{ $queue->queue }}.running">{{ $queue->running ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p style="font-size:.875rem;margin:.75rem 0 .25rem">Failed jobs: <strong data-figure="failed.day">{{ number_format($failedDay) }}</strong> in 24 h, <strong data-figure="failed.all">{{ number_format($failedAll) }}</strong> kept in all.</p>
        @if ($failures !== [])
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead><tr><th style="{{ $label }}">Job</th><th style="{{ $label }}">Queue</th><th style="{{ $label }}">Failed</th><th style="{{ $label }};min-width:14rem">Error</th>@if ($canManage)<th style="{{ $label }}"></th>@endif</tr></thead>
                    <tbody>
                        @foreach ($failures as $failure)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)" data-failed-job="{{ $failure->uuid }}">
                                <td style="{{ $label }}">{{ $failure->job }}</td>
                                <td style="{{ $label }}">{{ $failure->queue }}</td>
                                <td style="{{ $label }};white-space:nowrap">{{ W::lagos($failure->failedAt) ?? '—' }}</td>
                                <td style="{{ $label }};opacity:.85">{{ $failure->error ?? '—' }}</td>
                                @if ($canManage)
                                    <td style="{{ $label }};white-space:nowrap">
                                        <x-filament::link tag="button" wire:click="retryFailedJob('{{ $failure->uuid }}')" wire:confirm="Put this job back on its queue? It runs again from the start.">Retry</x-filament::link>
                                        ·
                                        <x-filament::link tag="button" color="danger" wire:click="forgetFailedJob('{{ $failure->uuid }}')" wire:confirm="Forget this failed job? Its record is removed and it will not run.">Forget</x-filament::link>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
