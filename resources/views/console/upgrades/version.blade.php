@php
    use App\Installer\Upgrades\ReleaseCheckStatus;
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;
    use App\Platform\Operations\Health\StorageHealth;

    /** @var \App\Installer\Upgrades\UpgradeState|null $state */
    /** @var \App\Installer\Upgrades\ReleaseCheck $release */
    $cell = 'padding:.45rem .5rem;text-align:left;vertical-align:top';
    $line = 'border-top:1px solid rgba(127,127,127,.2)';
    $backup = static fn ($run): string => $run === null ? 'never' : (W::lagos($run->finished_at?->toImmutable()) ?? '—').($run->size_bytes !== null ? ' ('.StorageHealth::bytes((int) $run->size_bytes).')' : '');
@endphp

<x-filament-panels::page>
    <x-filament::section heading="This installation">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <tbody>
                    <tr>
                        <th style="{{ $cell }};width:16rem">Version running</th>
                        <td style="{{ $cell }}" data-figure="version.current">{{ $state?->codeVersion ?? \App\Installer\AppVersion::current() }}</td>
                    </tr>
                    <tr style="{{ $line }}">
                        <th style="{{ $cell }}">Database upgraded for</th>
                        <td style="{{ $cell }}" data-figure="version.database">
                            {{ $state?->databaseVersion ?? 'unknown' }}
                            @if ($state?->seedersPending())
                                <x-filament::badge color="warning" style="display:inline-flex">install seeders not run for {{ $state->codeVersion }}</x-filament::badge>
                            @endif
                            @if ($state?->codeIsOlder())
                                <x-filament::badge color="danger" style="display:inline-flex">older code than the database</x-filament::badge>
                            @endif
                        </td>
                    </tr>
                    <tr style="{{ $line }}">
                        <th style="{{ $cell }}">Migrations not run</th>
                        <td style="{{ $cell }}">
                            @if ($state === null)
                                <span data-figure="version.pending">unknown: the database cannot be read</span>
                            @else
                                <span data-figure="version.pending">{{ count($state->migrations->pending) }}</span> of {{ $state->migrations->total }}
                                @if ($state->migrations->pending !== [])
                                    <ul style="margin:.35rem 0 0;padding-left:1.1rem;list-style:disc;font-family:ui-monospace,monospace;font-size:.8125rem" data-figure="version.pending.names">
                                        @foreach (array_slice($state->migrations->pending, 0, 20) as $name)
                                            <li>{{ $name }}</li>
                                        @endforeach
                                        @if (count($state->migrations->pending) > 20)
                                            <li>and {{ count($state->migrations->pending) - 20 }} more</li>
                                        @endif
                                    </ul>
                                @endif
                            @endif
                        </td>
                    </tr>
                    <tr style="{{ $line }}">
                        <th style="{{ $cell }}">Maintenance mode</th>
                        <td style="{{ $cell }}" data-figure="version.maintenance">
                            @if (! $maintenance)
                                Off
                            @elseif ($maintenanceForUpgrade)
                                On for an upgrade: visitors see the maintenance page, the console stays open
                            @else
                                On (php artisan down)
                            @endif
                        </td>
                    </tr>
                    <tr style="{{ $line }}">
                        <th style="{{ $cell }}">Last database backup</th>
                        <td style="{{ $cell }}" data-figure="version.backup.db">{{ $backup($lastDatabaseBackup) }}</td>
                    </tr>
                    <tr style="{{ $line }}">
                        <th style="{{ $cell }}">Last files backup</th>
                        <td style="{{ $cell }}" data-figure="version.backup.files">{{ $backup($lastFilesBackup) }}
                            @if ($backupsUrl !== null)
                                · <a href="{{ $backupsUrl }}" style="text-decoration:underline;text-underline-offset:2px">Operations → Backups</a>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Latest release" :description="$repository !== null ? 'Published releases of '.$repository.' on GitHub (pre-releases are not shown).' : null">
        <div style="font-size:.875rem" data-figure="version.latest">
            @if ($release->status === ReleaseCheckStatus::Found)
                <p style="margin:0">
                    <strong data-figure="version.latest.number">{{ $release->version }}</strong>
                    @if ($release->publishedAt !== null), published {{ W::lagos($release->publishedAt) }}@endif
                    @if ($state !== null && $release->isNewerThan($state->codeVersion))
                        <x-filament::badge color="info" style="display:inline-flex">newer than this installation</x-filament::badge>
                    @elseif ($state !== null && \App\Installer\AppVersion::isRelease($state->codeVersion) && \App\Installer\AppVersion::compare($release->version, $state->codeVersion) === 0)
                        <x-filament::badge color="success" style="display:inline-flex">up to date</x-filament::badge>
                    @endif
                </p>
                @if ($release->url !== null)
                    <p style="margin:.35rem 0 0"><a href="{{ $release->url }}" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;text-underline-offset:2px">Release notes and the zip on GitHub</a></p>
                @endif
            @else
                <p style="margin:0">{{ $release->message }}</p>
            @endif
            @if ($release->checkedAt !== null)
                <p style="margin:.35rem 0 0;opacity:.7">Checked {{ W::lagos($release->checkedAt) }}.</p>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="How to upgrade">
        <ol style="margin:0;padding-left:1.25rem;list-style:decimal;font-size:.875rem;line-height:1.6">
            <li>Download the release zip and its <code>.sha256</code> from GitHub; compare the checksum.</li>
            <li>Back up the database and the files (Operations → Backups) and wait until both succeed.</li>
            <li>Turn on maintenance mode (<code>php artisan down</code>, or the button above: the console stays open).</li>
            <li>Rename the GoProperty folder to <code>goproperty.previous</code>, extract the new release, copy <code>.env</code> and <code>storage/</code> across.</li>
            <li>Run the migrations and the install seeders: over SSH, or with <strong>Run pending migrations</strong> here (super-admins).</li>
            <li>Check the installation, then turn maintenance mode off (the upgrade step does it for you).</li>
        </ol>
        <p style="font-size:.8125rem;margin:.75rem 0 0;opacity:.8">Something wrong? Put the previous folder back and restore the database from the backup taken in step 2. Never roll migrations back. The whole runbook: docs/runbooks/upgrade.md.</p>
        @if ($canUpgrade && $state !== null && ! $state->pending())
            <p style="font-size:.8125rem;margin:.5rem 0 0" data-figure="version.nothing">Nothing to run: every migration has run{{ \App\Installer\AppVersion::isRelease($state->codeVersion) ? ' and the install seeders ran for '.$state->codeVersion : '' }}.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
