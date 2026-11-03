{{--
    Reporting someone else's post or comment (P07-04): "Report", then a
    short form under it (why, an optional note); afterwards "You reported
    this". $type (`group_post`, `group_comment`), $id, $reportedByMe,
    $reasons (Enums\ReportReason cases), $reporting (the open form's key).
--}}
@if ($reportedByMe)
    <span class="text-sm text-zinc-500 dark:text-zinc-400" data-test="reported">{{ __('You reported this') }}</span>
@elseif (($reporting ?? null) === $type.':'.$id)
    <form wire:submit="sendReport" class="flex w-full flex-col gap-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/60" data-test="report-form">
        <fieldset class="flex flex-col gap-1">
            <legend class="mb-1 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Why are you reporting it?') }}</legend>
            @foreach ($reasons as $reason)
                <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                    <input type="radio" wire:model="reportReason" value="{{ $reason->value }}" data-test="report-reason-{{ $reason->value }}">
                    {{ $reason->label() }}
                </label>
            @endforeach
        </fieldset>
        <flux:textarea wire:model="reportNote" :label="__('Anything to add? (optional)')" rows="2" maxlength="500" data-test="report-note" />
        <flux:text class="text-xs">{{ __('Only the agency sees reports. The author is not told who reported it.') }}</flux:text>
        @error('report')
            <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="report-error">{{ $message }}</flux:text>
        @enderror
        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" size="sm" variant="primary" class="min-h-11" data-test="send-report">{{ __('Send report') }}</flux:button>
            <flux:button type="button" size="sm" variant="ghost" class="min-h-11" wire:click="cancelReport">{{ __('Cancel') }}</flux:button>
        </div>
    </form>
@else
    <button type="button" wire:click="startReport('{{ $type }}', {{ $id }})" class="min-h-9 rounded-md px-2 text-sm font-medium text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" data-test="report">{{ __('Report') }}</button>
@endif
