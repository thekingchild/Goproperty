@php
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;

    $cell = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Alerts" description="Checked every minute by the scheduler; a stopped scheduler is noticed by web requests and the uptime link. Sent when they start, again every {{ $settings->renotifyMinutes }} minutes while they last, and once when they clear.">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.875rem;margin-bottom:.75rem">
            <span>Alerts: <strong data-figure="alerts.enabled">{{ $settings->enabled ? 'on' : 'off' }}</strong></span>
            <span>Slack: <strong data-figure="alerts.slack">{{ $settings->slackWebhookUrl !== null ? 'set' : 'not set' }}</strong></span>
            <span>Email: <strong data-figure="alerts.recipients">{{ $recipients }}</strong> staff</span>
            @if ($settingsUrl !== null)
                <a href="{{ $settingsUrl }}" style="text-decoration:underline;text-underline-offset:2px">Change where alerts go</a>
            @endif
        </div>

        @if ($open->isEmpty())
            <p data-figure="alerts.open" style="font-size:.875rem">Nothing is failing.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem" data-figure="alerts.open">
                    <thead>
                        <tr>
                            <th style="{{ $cell }}">Open</th>
                            <th style="{{ $cell }};min-width:16rem">What</th>
                            <th style="{{ $cell }};min-width:8rem">Since</th>
                            <th style="{{ $cell }};min-width:8rem">Last sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($open as $alert)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)" data-alert="{{ $alert->rule->value }}|{{ $alert->subject }}">
                                <td style="{{ $cell }}"><x-filament::badge color="danger" style="display:inline-flex">{{ $alert->rule->label() }}</x-filament::badge></td>
                                <td style="{{ $cell }}">{{ $alert->summary }}</td>
                                <td style="{{ $cell }}">{{ W::lagos($alert->started_at?->toImmutable()) }}</td>
                                <td style="{{ $cell }}">{{ W::lagos($alert->last_notified_at?->toImmutable()) ?? '—' }} <span style="opacity:.7">({{ $alert->notifications }}×)</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($cleared->isNotEmpty())
            <details style="margin-top:.75rem;font-size:.875rem">
                <summary style="cursor:pointer">Recently cleared ({{ $cleared->count() }})</summary>
                <ul style="margin:.5rem 0 0 1rem;list-style:disc">
                    @foreach ($cleared as $alert)
                        <li>{{ $alert->rule->label() }}{{ $alert->subject !== '' ? ' — '.$alert->subject : '' }}: {{ W::lagos($alert->started_at?->toImmutable()) }} to {{ W::lagos($alert->resolved_at?->toImmutable()) }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
