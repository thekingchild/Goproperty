@php
    use App\Platform\Operations\Health\StorageHealth as S;

    $cell = 'padding:.4rem .5rem;text-align:right;white-space:nowrap';
    $label = 'padding:.4rem .5rem;text-align:left';
@endphp

<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section heading="Storage" description="Uploaded files from the media library's records; table sizes are the database server's own estimates.">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.875rem;margin-bottom:.75rem">
            @forelse ($media as $disk)
                <span>Files on {{ $disk['disk'] }}: <strong data-figure="storage.media.{{ $disk['disk'] }}">{{ S::bytes($disk['bytes']) }}</strong> ({{ number_format($disk['files']) }} files)</span>
            @empty
                <span data-figure="storage.media">No uploaded files yet.</span>
            @endforelse
            <span>Database: <strong data-figure="storage.database">{{ $database === null ? 'not reported' : S::bytes($database['total']) }}</strong></span>
        </div>

        @if ($database !== null && $database['tables'] !== [])
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead><tr><th style="{{ $label }}">Largest tables</th><th style="{{ $cell }}">Size</th><th style="{{ $cell }}">Rows (about)</th><th style="{{ $cell }}">Growth{{ $database['since'] !== null ? ' since '.\Carbon\CarbonImmutable::parse($database['since'])->format('j M') : '' }}</th></tr></thead>
                    <tbody>
                        @foreach ($database['tables'] as $table)
                            <tr style="border-top:1px solid rgba(127,127,127,.2)">
                                <th style="{{ $label }};font-weight:400"><code>{{ $table['name'] }}</code></th>
                                <td style="{{ $cell }}">{{ S::bytes($table['bytes']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($table['rows']) }}</td>
                                <td style="{{ $cell }}">{{ $table['growth'] === null ? 'from tomorrow' : ($table['growth'] >= 0 ? '+' : '').S::bytes($table['growth']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
