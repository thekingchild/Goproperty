{{--
    The form a moderator fills before hiding, removing or muting (P07-04):
    why (kept in the trail; members never see it) and, for a mute, how
    long. Shown under the item whose action was started ($acting matches
    $key and $where). $key ("group_post:12"), $where (`feed`, `queue`).
--}}
@php
    [$actingAction, $actingKey, $actingWhere] = array_pad(explode('|', (string) ($acting ?? '')), 3, '');
@endphp
@if ($actingKey === $key && $actingWhere === $where)
    <form wire:submit="confirmModeration" class="flex flex-col gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/40" data-test="moderation-form" data-action="{{ $actingAction }}">
        <flux:heading size="sm" level="3">
            {{ match ($actingAction) {
                'hide' => __('Hide it from members'),
                'remove' => __('Remove it for good for members'),
                default => __('Mute its author'),
            } }}
        </flux:heading>
        <flux:text class="text-sm">
            {{ match ($actingAction) {
                'hide' => __('Members, its author too, stop seeing it at once. You can show it again later.'),
                'remove' => __('Members, its author too, stop seeing it at once, and it cannot be shown again. It stays here for moderators, with this record.'),
                default => __('They can still read the group, but not post, comment, react or report until then.'),
            } }}
        </flux:text>
        @if ($actingAction === 'mute')
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('How long') }}">
                @foreach ($muteLengths as $length)
                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <input type="radio" wire:model="muteDays" value="{{ $length->value }}" data-test="mute-length-{{ $length->value }}">
                        {{ $length->label() }}
                    </label>
                @endforeach
            </div>
        @endif
        <flux:textarea wire:model="reason" :label="__('Why? (for the record; members do not see it)')" rows="2" maxlength="500" data-test="moderation-reason-input" />
        @error('moderation')
            <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="moderation-error">{{ $message }}</flux:text>
        @enderror
        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" size="sm" :variant="$actingAction === 'remove' ? 'danger' : 'primary'" class="min-h-11" data-test="confirm-moderation">
                {{ match ($actingAction) { 'hide' => __('Hide'), 'remove' => __('Remove'), default => __('Mute') } }}
            </flux:button>
            <flux:button type="button" size="sm" variant="ghost" class="min-h-11" wire:click="cancelModeration">{{ __('Cancel') }}</flux:button>
        </div>
    </form>
@endif
