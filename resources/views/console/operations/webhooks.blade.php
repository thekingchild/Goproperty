@php
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;

    $cell = 'padding:.4rem .5rem;text-align:right;white-space:nowrap';
    $label = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Paystack webhooks" description="Last 24 hours. Failed = wrong signature or a processing error; failure rate = failed ÷ received. Awaiting = signed correctly, not processed yet.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead><tr><th style="{{ $label }}">Account</th><th style="{{ $cell }}">Received</th><th style="{{ $cell }}">Failed</th><th style="{{ $cell }}">Failure rate</th><th style="{{ $cell }}">Awaiting</th><th style="{{ $label }}">Last event</th></tr></thead>
                <tbody>
                    @foreach ([$own, ...$agencies] as $row)
                        @php($key = $row->organizationId === null ? 'webhooks.platform' : 'webhooks.org'.$row->organizationId)
                        <tr style="border-top:1px solid rgba(127,127,127,.2)">
                            <th style="{{ $label }}">
                                {{ $row->name }}
                                @if ($row->organizationId === null && $ownUrl !== null)
                                    <br><a href="{{ $ownUrl }}" style="font-weight:400;text-decoration:underline;text-underline-offset:2px">Deliveries and Replay</a>
                                @elseif ($row->connectionStatus !== null)
                                    <br><span style="font-weight:400;opacity:.7">connection: {{ str_replace('_', ' ', $row->connectionStatus) }}</span>
                                @endif
                            </th>
                            <td style="{{ $cell }}" data-figure="{{ $key }}.received">{{ number_format($row->received) }}</td>
                            <td style="{{ $cell }}" data-figure="{{ $key }}.failed">{{ number_format($row->failed) }}</td>
                            <td style="{{ $cell }}" data-figure="{{ $key }}.rate">{{ $row->rateLabel() }}</td>
                            <td style="{{ $cell }}" data-figure="{{ $key }}.awaiting">{{ number_format($row->awaiting) }}</td>
                            <td style="{{ $label }};white-space:nowrap" data-figure="{{ $key }}.last">{{ W::lagos($row->lastReceivedAt) ?? 'never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p style="font-size:.8125rem;opacity:.8;margin:.75rem 0 0">
            {{ number_format($connected) }} agencies have their own Paystack account connected; up to 20 are listed, those with failures first. Agencies' deliveries are shown, not replayed here.
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
