@php
    use App\Platform\Deliveries\Health\DeliveryOutcome as O;
    use App\Platform\Filament\Resources\DeliveryLog\DeliveryLogResource as Log;
    use App\Support\Money;

    $cell = 'padding:.4rem .5rem;text-align:right;white-space:nowrap';
    $label = 'padding:.4rem .5rem;text-align:left';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="SMS spend" description="SMS GoText took (from the delivery log) and SMS credits (from the credit ledger), across organizations.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead>
                    <tr>
                        <th style="{{ $label }}"></th>
                        @foreach ($spends as $spend)
                            <th style="{{ $cell }}">{{ $spend->window->label() }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">SMS sent</th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}">@include('console.operations.figure', ['key' => $spend->window->value.'.spend.messages', 'value' => number_format($spend->messages), 'url' => Log::filteredUrl('sms', O::Sent, $spend->window)])</td>
                        @endforeach
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">Pages</th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}">
                                <span data-figure="{{ $spend->window->value }}.spend.pages">{{ number_format($spend->pages) }}</span>
                                @if ($spend->pagesUnreported > 0)
                                    <br><span style="opacity:.7">+ <span data-figure="{{ $spend->window->value }}.spend.pages_unreported">{{ number_format($spend->pagesUnreported) }}</span> not reported</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">GoText's cost</th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}">
                                @if ($spend->messages === 0)
                                    <span data-figure="{{ $spend->window->value }}.spend.cost">{{ Money::zero()->format() }}</span>
                                @elseif ($spend->costUnreported === $spend->messages)
                                    <span data-figure="{{ $spend->window->value }}.spend.cost">Cost not reported by GoText</span>
                                @else
                                    <span data-figure="{{ $spend->window->value }}.spend.cost">{{ Money::kobo($spend->costKobo)->format() }}</span>
                                    @if (! $spend->costComplete())
                                        <br><span style="opacity:.7">cost not reported by GoText for <span data-figure="{{ $spend->window->value }}.spend.cost_unreported">{{ number_format($spend->costUnreported) }}</span></span>
                                    @endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">Credits used <span style="font-weight:400;opacity:.7">(taken for sends, less given back)</span></th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}"><span data-figure="{{ $spend->window->value }}.spend.credits_used">{{ number_format($spend->creditsUsed()) }}</span></td>
                        @endforeach
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">Credits given back <span style="font-weight:400;opacity:.7">(GoText refused the SMS)</span></th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}"><span data-figure="{{ $spend->window->value }}.spend.credits_given_back">{{ number_format($spend->creditsGivenBack) }}</span></td>
                        @endforeach
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">Credits bought</th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}"><span data-figure="{{ $spend->window->value }}.spend.credits_bought">{{ number_format($spend->creditsBought) }}</span></td>
                        @endforeach
                    </tr>
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <th style="{{ $label }}">Monthly allowance granted</th>
                        @foreach ($spends as $spend)
                            <td style="{{ $cell }}"><span data-figure="{{ $spend->window->value }}.spend.allowance">{{ number_format($spend->allowanceGranted) }}</span></td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
        <p style="font-size:.8125rem;opacity:.8;margin:.75rem 0 0">
            One credit is one SMS page, taken before GoText is called. Pages and cost are GoText's own figures, shown as far as it reports them. Credits count by when the ledger entry was written.
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
