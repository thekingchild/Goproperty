@php
    use App\Platform\Deliveries\Health\ChannelFigures;
    use App\Platform\Deliveries\Health\DeliveryOutcome as O;
    use App\Platform\Deliveries\Health\DeliveryWindow as W;
    use App\Platform\Deliveries\Health\ProviderHealth;
    use App\Platform\Filament\Resources\DeliveryLog\DeliveryLogResource as Log;
    use App\Platform\Filament\Widgets\Operations\OperationsWidget;

    $cell = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Provider status" description="From the messages queued in the last 7 days; the health word from the attempts of the last 24 hours.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead>
                    <tr>
                        <th style="{{ $cell }}">Provider</th>
                        <th style="{{ $cell }};min-width:9rem">Health (24 h)</th>
                        <th style="{{ $cell }};min-width:8rem">Last message taken</th>
                        <th style="{{ $cell }};min-width:12rem">Last failure</th>
                        <th style="{{ $cell }}">Delivery reports</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($providers as $provider)
                        @php($key = 'provider.'.$provider->channel.'.'.$provider->provider)
                        <tr style="border-top:1px solid rgba(127,127,127,.2)" data-provider="{{ $provider->channel }}.{{ $provider->provider }}">
                            <td style="{{ $cell }}"><strong>{{ ChannelFigures::channelLabel($provider->channel) }}</strong><br>{{ $provider->label() }}</td>
                            <td style="{{ $cell }}">
                                <x-filament::badge :color="$provider->health()->color()" style="display:inline-flex">
                                    <span data-figure="{{ $key }}.health">{{ $provider->health()->label() }}</span>
                                </x-filament::badge>
                                @if ($provider->attempted > 0)
                                    <div style="margin-top:.25rem;opacity:.8;white-space:nowrap">
                                        @include('console.operations.figure', ['key' => $key.'.failure_rate', 'value' => $provider->failureRate(), 'url' => Log::filteredUrl($provider->channel, O::Problems, W::Day)])
                                        of <span data-figure="{{ $key }}.attempted">{{ number_format($provider->attempted) }}</span> failed
                                    </div>
                                @else
                                    <div style="margin-top:.25rem;opacity:.8">No message attempted in 24 h</div>
                                @endif
                            </td>
                            <td style="{{ $cell }}"><span data-figure="{{ $key }}.last_sent_at">{{ OperationsWidget::lagos($provider->lastSentAt) ?? 'None in 7 days' }}</span></td>
                            <td style="{{ $cell }}">
                                @if ($provider->lastFailureAt !== null || $provider->lastFailureError !== null)
                                    <span data-figure="{{ $key }}.last_failure_at">{{ OperationsWidget::lagos($provider->lastFailureAt) ?? '—' }}</span>
                                    @if ($provider->lastFailureError !== null)
                                        <br><span data-figure="{{ $key }}.last_failure_error" style="opacity:.8">{{ $provider->lastFailureError }}</span>
                                    @endif
                                @else
                                    <span data-figure="{{ $key }}.last_failure_at">None in 7 days</span>
                                @endif
                            </td>
                            <td style="{{ $cell }}">
                                @if ($provider->channel === 'sms')
                                    Asked of GoText (no webhook) 5 min, 30 min, 2 h and 12 h after sending.
                                    <br><span style="opacity:.8">Last asked: <span data-figure="{{ $key }}.last_status_check_at">{{ OperationsWidget::lagos($provider->lastStatusCheckAt) ?? 'not in 7 days' }}</span></span>
                                @elseif ($provider->channel === 'whatsapp')
                                    Meta's signed status webhook, per agency.
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <td style="{{ $cell }}"><strong>WhatsApp</strong><br>Agencies' own connections</td>
                        <td style="{{ $cell }}" colspan="4">
                            <span data-figure="whatsapp.connections.active">{{ number_format($connections['active']) }}</span> connected,
                            <span data-figure="whatsapp.connections.failing">{{ number_format($connections['failing']) }}</span> failing (Meta refused the access token),
                            <span data-figure="whatsapp.connections.pending_test">{{ number_format($connections['pending_test']) }}</span> not checked yet.
                            <span style="opacity:.8">Each agency sends through its own WhatsApp Business account; a failing connection falls back to SMS.</span>
                        </td>
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <td style="{{ $cell }}"><strong>Email</strong><br>Mailer: {{ $mailer }}</td>
                        <td style="{{ $cell }}" colspan="4">
                            Sends are not logged yet, so there is no health word. Last bounce or complaint webhook call:
                            <span data-figure="email.last_webhook_at">{{ OperationsWidget::lagos($lastEmailWebhookAt) ?? 'never' }}</span>.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p style="font-size:.8125rem;opacity:.8;margin:.75rem 0 0" data-rule>
            <strong>OK</strong> below {{ ProviderHealth::DEGRADED_FROM_PERCENT }} % failed or not delivered in the last 24 hours,
            <strong>degraded</strong> from {{ ProviderHealth::DEGRADED_FROM_PERCENT }} %, <strong>failing</strong> from {{ ProviderHealth::FAILING_FROM_PERCENT }} %.
            Errors are shown with phone numbers masked, never the message.
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
