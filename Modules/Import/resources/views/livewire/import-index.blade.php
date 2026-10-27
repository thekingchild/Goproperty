{{--
    Imports (/o/{organization}/imports; Modules\Import\Livewire\ImportIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6).
--}}
<x-page :heading="__('Import')" :description="__('Bring your records across from Excel or CSV. Every import starts with a dry run: nothing is saved until you commit.')">
    @if ($canImport)
        <x-slot:actions>
            <flux:button icon="arrow-down-tray" :href="route('workspace.imports.template', $organization)" data-test="download-template">{{ __('Download template') }}</flux:button>
            <flux:button variant="primary" icon="arrow-up-tray" :href="route('workspace.imports.create', $organization)" wire:navigate data-test="new-import">{{ __('New import') }}</flux:button>
        </x-slot:actions>
    @endif

    @if ($batches->isEmpty())
        <x-empty-state icon="arrow-up-tray" :heading="__('No imports yet')" :description="__('Download the template, fill in your owners, buildings, units and tenants, and upload it. Or upload your own spreadsheet and match its columns.')">
            @if ($canImport)
                <x-slot:action>
                    <flux:button variant="primary" icon="arrow-up-tray" :href="route('workspace.imports.create', $organization)" wire:navigate>{{ __('New import') }}</flux:button>
                </x-slot:action>
            @endif
        </x-empty-state>
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="imports-list">
            @foreach ($batches as $batch)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="batch-{{ $batch->id }}" data-batch="{{ $batch->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.imports.show', [$organization, $batch->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $batch->file_name }}</a>
                        <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                            {{ collect([$batch->createdBy?->name, $batch->created_at?->format('d/m/Y H:i'), $batch->rows_total > 0 ? trans_choice(':count row|:count rows', $batch->rows_total) : null])->filter()->join(' · ') }}
                        </span>
                    </div>
                    <flux:badge size="sm" :color="$batch->status->color()">{{ __($batch->status->label()) }}</flux:badge>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$batches" />
    @endif
</x-page>
