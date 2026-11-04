@php
    $row = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="App" description="The release running here. Uptime, p95 response time and the error rate per request need an outside monitor and are not shown.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <tbody>
                    <tr><th style="{{ $row }}">Version</th><td style="{{ $row }}" data-figure="app.version">{{ $app['version'] }}</td>
                        <th style="{{ $row }}">Environment</th><td style="{{ $row }}" data-figure="app.environment">{{ $app['environment'] }}@if ($app['debug']) <x-filament::badge color="warning" style="display:inline-flex">debug on</x-filament::badge>@endif</td></tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)"><th style="{{ $row }}">PHP / Laravel</th><td style="{{ $row }}">{{ $app['php'] }} / {{ $app['laravel'] }}</td>
                        <th style="{{ $row }}">Hosting profile</th><td style="{{ $row }}">{{ $app['hosting'] }}</td></tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)"><th style="{{ $row }}">Maintenance mode</th><td style="{{ $row }}" data-figure="app.maintenance">{{ $app['maintenance'] ? 'On' : 'Off' }}</td>
                        <th style="{{ $row }}">Migrations not run</th><td style="{{ $row }}" data-figure="app.pending_migrations">{{ $app['pending_migrations'] ?? 'unknown' }}</td></tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)"><th style="{{ $row }}">Errors reported, 15 min</th><td style="{{ $row }}" data-figure="app.errors_15m">{{ number_format($app['errors_15m']) }}</td>
                        <th style="{{ $row }}">Errors reported, 1 h</th><td style="{{ $row }}" data-figure="app.errors_1h">{{ number_format($app['errors_1h']) }}</td></tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
