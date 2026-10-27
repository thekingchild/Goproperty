{{-- Settings → Lease contract (Modules\Leasing\Livewire\ContractSettingsPage). --}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.documents.lease-contract" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" level="2">{{ __('Lease contract clauses') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The terms printed on every new tenancy agreement, after the parties, the rent and the deposit. Leave a blank line between clauses: each is numbered on the contract. Contracts already issued keep the clauses they were issued with.') }}</flux:text>

        <form wire:submit="save" class="mt-5 grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <flux:textarea wire:model.blur="clauses" :label="__('Clauses')" rows="18" :maxlength="$maxLength" :disabled="! $canEdit" required data-test="clauses" />
                @if ($unknown !== [])
                    <flux:text class="mt-2 text-sm text-amber-700 dark:text-amber-400" data-test="unknown-placeholders">
                        {{ __('Not a placeholder, printed as typed:') }} @foreach ($unknown as $name)<code>{{ '{'.$name.'}' }}</code>@if (! $loop->last), @endif @endforeach
                    </flux:text>
                @endif
                @if ($canEdit)
                    <div class="mt-4 flex flex-wrap gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Save clauses') }}</flux:button>
                        <flux:button type="button" wire:click="restoreDefault" wire:confirm="{{ __('Replace these clauses with the standard ones?') }}">{{ __('Restore the standard clauses') }}</flux:button>
                    </div>
                @else
                    <flux:text class="mt-4">{{ __('You can see these clauses but not change them.') }}</flux:text>
                @endif
            </div>

            <aside class="text-sm">
                <flux:heading size="sm" level="3">{{ __('Placeholders') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Type one in curly brackets and the contract fills it in. Nothing else is filled in or run: anything else prints exactly as typed.') }}</flux:text>
                <dl class="mt-3 space-y-2" data-test="placeholders">
                    @foreach ($placeholders as $name => $meaning)
                        <div>
                            <dt><code class="text-zinc-900 dark:text-white">{{ '{'.$name.'}' }}</code></dt>
                            <dd class="text-zinc-600 dark:text-zinc-400">{{ __($meaning) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </aside>
        </form>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" level="2">{{ __('Preview') }}</flux:heading>
        <flux:text class="mt-1">{{ __('With sample details, not a real tenant\'s.') }}</flux:text>
        <ol class="mt-4 list-decimal space-y-2 ps-6 text-sm text-zinc-800 dark:text-zinc-200" data-test="preview">
            @foreach ($preview as $clause)
                <li>{!! nl2br(e($clause), false) !!}</li>
            @endforeach
        </ol>
    </section>
</x-page>
