@php
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;

    $cell = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Security" description="Last 24 hours, from the sign-in log; support sessions open now.">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.875rem;margin-bottom:.75rem">
            <span>Failed customer sign-ins: <strong data-figure="security.failed_customers">{{ number_format($failures['customers']) }}</strong></span>
            <span>Failed staff sign-ins: <strong data-figure="security.failed_staff">{{ number_format($failures['staff']) }}</strong></span>
            <span>Lockouts: <strong data-figure="security.lockouts">{{ number_format($failures['lockouts']) }}</strong></span>
            <span>Support sessions open: <strong data-figure="security.sessions">{{ $sessions['read_only'] + $sessions['write'] }}</strong>@if ($sessions['write'] > 0) <span style="opacity:.8">({{ $sessions['write'] }} in write mode)</span>@endif</span>
            <span>Staff sign-ins: <strong data-figure="security.staff_sign_ins">{{ number_format($staffSignInCount) }}</strong></span>
        </div>
        @if ($staffSignIns !== [])
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead><tr><th style="{{ $cell }}">Staff member</th><th style="{{ $cell }}">Signed in</th><th style="{{ $cell }}">Country</th><th style="{{ $cell }}">Device</th></tr></thead>
                    <tbody>
                        @foreach ($staffSignIns as $signIn)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)">
                                <td style="{{ $cell }}">{{ $signIn['name'] }}</td>
                                <td style="{{ $cell }};white-space:nowrap">{{ W::lagos($signIn['at']) }}</td>
                                <td style="{{ $cell }}">{{ $signIn['country'] ?? '—' }}</td>
                                <td style="{{ $cell }}">@if ($signIn['new_device'])<x-filament::badge color="warning" style="display:inline-flex">New device</x-filament::badge>@else Known @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($accessLogUrl !== null)
            <p style="font-size:.8125rem;margin:.75rem 0 0"><a href="{{ $accessLogUrl }}" style="text-decoration:underline;text-underline-offset:2px">The sign-in (access) log</a></p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
