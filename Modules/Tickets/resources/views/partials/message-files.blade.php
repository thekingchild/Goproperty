{{--
    The file picker of a reply (P04-02): photos (JPEG, PNG, WebP, HEIC) and
    PDFs, 10 MB each, at most 5 a message; the server checks every file from
    its bytes again. 44 px tall (phones). $id: the input's id.
--}}
<div class="flex flex-col gap-1">
    <label class="flex min-h-11 cursor-pointer flex-col gap-2">
        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Photos or PDFs (optional, up to :count)', ['count' => \Modules\Tickets\Messages\TicketMessages::MAX_FILES]) }}</span>
        <input id="{{ $id }}" type="file" wire:model="files" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,application/pdf"
            class="block min-h-11 w-full max-w-full rounded-lg border border-zinc-200 bg-white text-sm text-zinc-700 file:me-3 file:min-h-11 file:border-0 file:bg-zinc-100 file:px-4 file:font-medium file:text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-100"
            data-test="message-files-input">
    </label>
    <div wire:loading wire:target="files" class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Uploading…') }}</div>
    @error('files')
        <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="message-files-error">{{ $message }}</flux:text>
    @enderror
    @error('files.*')
        <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="message-files-error">{{ $message }}</flux:text>
    @enderror
</div>
