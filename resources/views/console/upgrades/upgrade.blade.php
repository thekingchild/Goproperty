@php
    use App\Installer\AppVersion;
    use App\Platform\Filament\Widgets\Operations\OperationsWidget as W;

    /** @var \App\Installer\Upgrades\UpgradeState|null $state */
    $pending = $state?->pending() ?? false;
    $kind = $result['kind'] ?? null;
    $auto = $fresh && $pending && ($result['auto'] ?? false) === true && $errors->isEmpty();
    $done = $state?->migrations->done ?? 0;
    $total = $state?->migrations->total ?? 0;
    $backupAt = $lastDatabaseBackup?->finished_at?->toImmutable();
    $field = 'display:block;width:100%;max-width:22rem;margin-top:.25rem;padding:.45rem .6rem;border:1px solid rgba(127,127,127,.45);border-radius:.5rem;background:transparent';
    $button = 'display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:.5rem;font-weight:600;font-size:.875rem;background:var(--primary-600);color:#fff;border:0;cursor:pointer';
@endphp

<x-filament-panels::page>
    @if ($result !== null)
        <div role="{{ $kind === 'failed' || $kind === 'refused' ? 'alert' : 'status' }}" data-upgrade-result="{{ $kind }}"
            style="padding:.75rem 1rem;border-radius:.75rem;font-size:.875rem;border:1px solid {{ $kind === 'failed' || $kind === 'refused' ? 'rgba(220,38,38,.5)' : 'rgba(22,163,74,.5)' }}">
            <strong>{{ $result['message'] }}</strong>
            @if (isset($result['error']))
                <div style="margin-top:.35rem;font-family:ui-monospace,monospace;font-size:.8125rem" data-upgrade-error>{{ $result['error'] }}</div>
            @endif
            @if ($kind === 'failed')
                <p style="margin:.5rem 0 0">Fix the cause (the error above; the log has more) and run the step again: migrations that finished are kept. Or roll back: put the previous folder back and restore the database from the backup taken before the upgrade (docs/runbooks/upgrade.md). Never roll migrations back.</p>
            @endif
        </div>
    @endif

    @foreach ($errors->all() as $message)
        <div role="alert" style="padding:.75rem 1rem;border-radius:.75rem;font-size:.875rem;border:1px solid rgba(220,38,38,.5)">{{ $message }}</div>
    @endforeach

    <x-filament::section heading="Progress">
        @if ($state === null)
            <p style="font-size:.875rem">The database cannot be read. Check <code>DB_*</code> in <code>.env</code>.</p>
        @else
            <p style="font-size:.875rem;margin:0 0 .5rem">
                @if ($pending)
                    From <strong data-figure="upgrade.from">{{ $state->databaseVersion ?? 'unknown' }}</strong> to <strong data-figure="upgrade.to">{{ $state->codeVersion }}</strong>.
                @else
                    This installation runs <strong data-figure="upgrade.to">{{ $state->codeVersion }}</strong>.
                @endif
            </p>
            <progress max="{{ max(1, $total) }}" value="{{ $done }}" style="width:100%;height:1rem" aria-describedby="upgrade-progress">{{ $done }} of {{ $total }}</progress>
            <p id="upgrade-progress" role="status" aria-live="polite" style="font-size:.875rem;margin:.35rem 0 0">
                <b data-progress>{{ $done }} of {{ $total }}</b> migrations done{{ $state->migrations->finished() ? '' : '…' }}
                @if ($state->migrations->finished() && $state->seedersPending())
                    · next: the install seeders and caches
                @endif
            </p>
            <p style="font-size:.8125rem;margin:.5rem 0 0">
                Maintenance mode: <span data-figure="upgrade.maintenance">{{ ! $maintenance ? 'off' : ($maintenanceForUpgrade ? 'on for the upgrade (the console stays open)' : 'on') }}</span>.
                Last database backup: <span data-figure="upgrade.backup">{{ $backupAt !== null ? W::lagos($backupAt) : 'none recorded here' }}</span>.
            </p>
        @endif
    </x-filament::section>

    @if ($state !== null && ! $pending)
        <x-filament::section heading="Nothing to run">
            <p style="font-size:.875rem;margin:0" data-upgrade-done>
                Every migration has run{{ AppVersion::isRelease($state->codeVersion) ? ' and the install seeders ran for '.$state->codeVersion : '' }}.
                @if ($maintenance)
                    Maintenance mode is still on: {{ $maintenanceForUpgrade ? 'turn it off on the Version page.' : 'end it with php artisan up.' }}
                @endif
                <a href="{{ $versionUrl }}" style="text-decoration:underline;text-underline-offset:2px">Back to Version</a>
            </p>
        </x-filament::section>
    @elseif ($state !== null && ! $fresh)
        <x-filament::section heading="Confirm it is you" description="The upgrade changes the database for every customer: your password{{ $needsCode ? ' and authenticator code' : '' }} first. It stays confirmed for {{ \App\Platform\Console\StepUp::MINUTES }} minutes.">
            <form method="post" action="{{ $confirmUrl }}" id="upgrade-confirm" style="display:grid;gap:.75rem;font-size:.875rem">
                @csrf
                <div>
                    <label for="upgrade-password">Your password</label>
                    <input id="upgrade-password" type="password" name="password" required autocomplete="current-password" style="{{ $field }}">
                </div>
                @if ($needsCode)
                    <div>
                        <label for="upgrade-code">Code from your authenticator app</label>
                        <input id="upgrade-code" type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" style="{{ $field }}">
                    </div>
                @endif
                <label for="upgrade-backed-up" style="display:flex;gap:.5rem;align-items:flex-start">
                    <input id="upgrade-backed-up" type="checkbox" name="backed_up" value="1" required style="margin-top:.2rem">
                    <span>A database backup was taken before the new release's files went up{{ $backupAt !== null ? ' (the last one recorded here: '.W::lagos($backupAt).')' : '' }}. If anything goes wrong, rolling back means restoring it.</span>
                </label>
                <div><button type="submit" style="{{ $button }}">Confirm and start</button></div>
            </form>
        </x-filament::section>
    @elseif ($state !== null)
        <x-filament::section heading="Run the upgrade">
            <form method="post" action="{{ $runUrl }}" id="upgrade-run" style="font-size:.875rem">
                @csrf
                <p style="margin:0 0 .75rem">Each step runs a few migrations (or, at the end, the install seeders and caches) in one short request. It turns maintenance mode on first, if it is not on yet, and off again at the end.</p>
                <button type="submit" style="{{ $button }}">{{ $kind === 'failed' ? 'Try this step again' : ($done === 0 && $kind === null ? 'Run the upgrade' : 'Continue') }}</button>
            </form>
        </x-filament::section>
    @endif

    @if ($auto)
        <script>
            // Carries on with the next step (one request each, INSTALLER.md § 10).
            window.setTimeout(function () { var form = document.getElementById('upgrade-run'); if (form) { form.submit(); } }, 250);
        </script>
    @endif
</x-filament-panels::page>
