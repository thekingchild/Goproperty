@php
    use App\Platform\Deliveries\Health\ChannelFigures;
    use App\Platform\Deliveries\Health\DeliveryOutcome as O;
    use App\Platform\Filament\Resources\DeliveryLog\DeliveryLogResource as Log;

    $columns = [
        [O::Sent, 'Sent'],
        [O::Delivered, 'Delivered'],
        [O::AwaitingReport, 'No report yet'],
        [O::Undelivered, 'Not delivered'],
        [O::Failed, 'Failed'],
        [O::NoCredit, 'No SMS credit'],
        [O::Waiting, 'Still waiting'],
    ];
    $cell = 'padding:.4rem .5rem;text-align:right;white-space:nowrap';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Messaging" description="Messages GoProperty queued for every organization, by what became of them. A message counts in the period it was queued in.">
        @foreach ($windows as $block)
            @php($window = $block['window'])
            <div style="overflow-x:auto;margin-bottom:1.25rem" data-window="{{ $window->value }}">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead>
                        <tr>
                            <th style="padding:.4rem .5rem;text-align:left;white-space:nowrap;min-width:7rem">{{ $window->label() }}</th>
                            <th style="{{ $cell }}">Queued</th>
                            @foreach ($columns as [$outcome, $label])
                                <th style="{{ $cell }}">{{ $label }}</th>
                            @endforeach
                            <th style="{{ $cell }}">Failure rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['channels'] as $channel => $figures)
                            @php($prefix = $window->value.'.'.$channel)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)">
                                <th style="padding:.4rem .5rem;text-align:left;font-weight:600">{{ ChannelFigures::channelLabel($channel) }}</th>
                                <td style="{{ $cell }}">@include('console.operations.figure', ['key' => $prefix.'.queued', 'value' => number_format($figures->total()), 'url' => Log::filteredUrl($channel, null, $window)])</td>
                                @foreach ($columns as [$outcome, $label])
                                    <td style="{{ $cell }}">
                                        @if ($outcome === O::NoCredit && $channel !== 'sms')
                                            <span title="Only SMS uses credits">—</span>
                                        @else
                                            @include('console.operations.figure', ['key' => $prefix.'.'.$outcome->value, 'value' => number_format($figures->count($outcome)), 'url' => Log::filteredUrl($channel, $outcome, $window)])
                                        @endif
                                    </td>
                                @endforeach
                                <td style="{{ $cell }}">
                                    @include('console.operations.figure', ['key' => $prefix.'.failure_rate', 'value' => $figures->failureRate() ?? '—', 'url' => Log::filteredUrl($channel, O::Problems, $window)])
                                    <span style="opacity:.7">of @include('console.operations.figure', ['key' => $prefix.'.attempted', 'value' => number_format($figures->count(O::Attempted)), 'url' => Log::filteredUrl($channel, O::Attempted, $window)])</span>
                                </td>
                            </tr>
                        @endforeach
                        <tr style="border-top:1px solid rgba(127,127,127,.2)" data-channel="email">
                            <th style="padding:.4rem .5rem;text-align:left;font-weight:600">Email</th>
                            <td colspan="{{ count($columns) + 2 }}" style="padding:.4rem .5rem">
                                Sends are not logged yet. Reported by the email provider:
                                <span data-figure="{{ $window->value }}.email.bounced">{{ number_format($block['email']->bounced) }}</span> {{ $block['email']->bounced === 1 ? 'address' : 'addresses' }} suppressed after a permanent bounce,
                                <span data-figure="{{ $window->value }}.email.complaints">{{ number_format($block['email']->complaints) }}</span> after a spam complaint
                                (<span data-figure="{{ $window->value }}.email.webhook_calls">{{ number_format($block['email']->webhookCalls) }}</span> webhook {{ $block['email']->webhookCalls === 1 ? 'call' : 'calls' }}).
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endforeach

        <p style="font-size:.8125rem;opacity:.8;margin:0" data-rule>
            <strong>Sent</strong> = taken by the provider (delivered, not delivered, or no report yet).
            <strong>Failure rate</strong> = (failed + not delivered) ÷ attempted, where attempted = sent + failed.
            Messages still waiting, and SMS not sent because the organization had no credit left, are not attempts and never count as failures.
            A failed SMS was retried three times first; WhatsApp "read" counts as delivered.
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
