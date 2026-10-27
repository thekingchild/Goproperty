{{--
    The Export button of a workspace list (App\Exports\Livewire\WithListExports;
    AGENT.md § 5). Put it in the page's actions, inside the Livewire view:
    <x-list-export list="owners" />. It shows only when the member may export
    the list; the export itself checks again. A refusal (too many exports,
    too many rows) shows under the button.
--}}
@props(['list', 'label' => null, 'size' => null])

@php($component = \Livewire\Livewire::current())

@if ($component !== null && method_exists($component, 'canExport') && $component->canExport($list))
    <div class="flex flex-col items-end gap-1" data-test="export-{{ $list }}">
        <flux:dropdown position="bottom" align="end">
            <flux:button :size="$size" icon="arrow-down-tray" icon:trailing="chevron-down" wire:loading.attr="disabled" wire:target="export">{{ $label ?? __('Export') }}</flux:button>

            <flux:menu>
                <flux:menu.item icon="table-cells" wire:click="export({{ \Illuminate\Support\Js::from($list) }}, 'xlsx')" data-test="export-{{ $list }}-xlsx">{{ __('Excel (.xlsx)') }}</flux:menu.item>
                <flux:menu.item icon="document-text" wire:click="export({{ \Illuminate\Support\Js::from($list) }}, 'csv')" data-test="export-{{ $list }}-csv">{{ __('CSV (.csv)') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>

        @error('export.'.$list)
            <flux:text class="max-w-xs text-end text-sm text-red-600 dark:text-red-400" role="alert" data-test="export-error">{{ $message }}</flux:text>
        @enderror
    </div>
@endif
