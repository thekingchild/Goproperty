<x-filament-panels::page>
    @php($versions = $this->getVersions())
    @if ($versions === [])
        <x-filament::section>No version has been published yet. The shipped default theme is live.</x-filament::section>
    @else
        <x-filament::section>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem" data-test="versions">
                <thead>
                    <tr style="text-align:left">
                        <th style="padding:.5rem">Version</th>
                        <th style="padding:.5rem">Published</th>
                        <th style="padding:.5rem">By</th>
                        <th style="padding:.5rem"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($versions as $version)
                        <tr style="border-top:1px solid rgba(127,127,127,.2)">
                            <td style="padding:.5rem">
                                {{ $version['version'] }}
                                @if ($version['status'] === 'published')
                                    <x-filament::badge color="success" style="display:inline-flex;margin-left:.5rem">Live</x-filament::badge>
                                @endif
                            </td>
                            <td style="padding:.5rem">{{ $version['published_at'] ?? '—' }}</td>
                            <td style="padding:.5rem">{{ $version['by'] ?? '—' }}</td>
                            <td style="padding:.5rem;text-align:right">
                                @if ($version['status'] !== 'published')
                                    {{ ($this->restoreAction)(['version' => $version['version']]) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

</x-filament-panels::page>
