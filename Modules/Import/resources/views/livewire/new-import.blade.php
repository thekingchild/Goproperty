{{-- New import (/o/{organization}/imports/new; Modules\Import\Livewire\NewImport). --}}
<x-page :heading="__('New import')" :description="__('Upload the filled-in template or your own spreadsheet. You check how its columns match before anything is saved.')">
    @include('import::livewire.partials.steps', ['current' => 'upload'])

    <form wire:submit="upload" class="flex max-w-2xl flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-2">
            <flux:label for="import-file">{{ __('File') }}</flux:label>
            <input id="import-file" type="file" wire:model="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" class="block text-sm text-zinc-700 file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800" data-test="import-file">
            <flux:text class="text-sm">{{ __('Excel (.xlsx) or CSV, up to 20 MB and 10,000 rows a sheet. Older .xls files: open them in Excel and save as .xlsx first.') }}</flux:text>
            <div wire:loading wire:target="file" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>
            <flux:error name="file" />
        </div>

        @if ($targets !== [])
            <flux:select wire:model="target" :label="__('For a CSV file: what does it hold?')" :description="__('A workbook names its sheets; for a CSV file we work it out from the column names unless you choose.')">
                <option value="">{{ __('Work it out from the columns') }}</option>
                @foreach ($targets as $target)
                    <option value="{{ $target->key() }}">{{ __($target->label()) }}</option>
                @endforeach
            </flux:select>
        @endif

        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="upload-import">{{ __('Upload and match columns') }}</flux:button>
            <flux:button :href="route('workspace.imports.template', $organization)" icon="arrow-down-tray">{{ __('Download template') }}</flux:button>
        </div>
    </form>
</x-page>
