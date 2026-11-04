@php
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;
    use App\Platform\Operations\Health\BackupHealth;
    use App\Platform\Operations\Health\StorageHealth;

    $cell = 'padding:.4rem .5rem;text-align:left;vertical-align:top';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Backups" description="Nightly (database, then files; the next runs below), verified on the 1st of each month; a restore drill is due each quarter.">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.875rem;margin-bottom:.75rem">
            @foreach (BackupHealth::TYPES as $type => $name)
                <span>Last {{ strtolower($name) }} backup: <strong data-figure="backup.{{ $type }}.last">{{ W::lagos($last[$type]?->finished_at?->toImmutable()) ?? 'never' }}</strong>
                    @if (($last[$type]?->size_bytes) !== null)({{ StorageHealth::bytes((int) $last[$type]->size_bytes) }})@endif;
                    next <span data-figure="backup.{{ $type }}.next">{{ W::lagos($next[$type] ?? null) ?? '—' }}</span></span>
            @endforeach
            <span>Last verify: <strong data-figure="backup.verify">{{ $verify === null ? 'never' : W::lagos($verify->finished_at?->toImmutable()).' ('.($verify->status->value === 'succeeded' ? 'passed' : 'failed').')' }}</strong></span>
            <span>Last drill: <strong data-figure="backup.drill">{{ $drill === null ? 'never' : $drill->drill_date?->format('j M Y').' ('.($drill->status->value === 'succeeded' ? 'passed' : 'failed').')' }}</strong></span>
        </div>

        @if ($destinations === [])
            <p style="font-size:.875rem">No active backup destination.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead><tr><th style="{{ $cell }}">Destination</th><th style="{{ $cell }}">Type</th><th style="{{ $cell }}">Latest</th><th style="{{ $cell }}">Result</th><th style="{{ $cell }}">Size</th></tr></thead>
                    <tbody>
                        @foreach ($destinations as $row)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)" data-figure="backup.{{ $row['type'] }}.{{ $row['destination']->id }}">
                                <td style="{{ $cell }}">{{ $row['destination']->name }}</td>
                                <td style="{{ $cell }}">{{ BackupHealth::TYPES[$row['type']] }}</td>
                                <td style="{{ $cell }};white-space:nowrap">{{ W::lagos($row['run']?->finished_at?->toImmutable()) ?? 'none yet' }}</td>
                                <td style="{{ $cell }}">
                                    @if ($row['run'] === null)
                                        —
                                    @elseif ($row['run']->status === 'success')
                                        <x-filament::badge color="success" style="display:inline-flex">Succeeded</x-filament::badge>
                                    @else
                                        <x-filament::badge color="danger" style="display:inline-flex">Failed</x-filament::badge>
                                    @endif
                                </td>
                                <td style="{{ $cell }};white-space:nowrap">{{ $row['run']?->size_bytes !== null ? StorageHealth::bytes((int) $row['run']->size_bytes) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($backupsUrl !== null)
            <p style="font-size:.8125rem;margin:.75rem 0 0"><a href="{{ $backupsUrl }}" style="text-decoration:underline;text-underline-offset:2px">Operations → Backups</a></p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
