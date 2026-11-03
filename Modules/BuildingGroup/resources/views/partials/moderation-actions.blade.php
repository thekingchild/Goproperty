{{--
    A moderator's buttons on a post or comment (P07-04), as their
    permissions allow: pin or unpin (published posts), hide, show again,
    remove, mute the tenant who wrote it, dismiss its open reports.
    $type (`group_post`, `group_comment`), $id, $status (Enums\PostStatus),
    $pinned, $fromTenant, $openReports, $canModerate, $canRemove, $canMute,
    $where (`feed`, `queue`), $mine (the moderator's own item: they remove
    it as its author instead, so no hide or remove here).
--}}
@php($published = $status === \Modules\BuildingGroup\Enums\PostStatus::Published)
@php($buttonClass = 'min-h-9 rounded-md px-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800')
<div class="flex flex-wrap items-center gap-1" data-test="moderation-actions">
    @if ($canModerate && $type === 'group_post' && $published && $where === 'feed')
        @if ($pinned)
            <button type="button" wire:click="unpin({{ $id }})" class="{{ $buttonClass }}" data-test="unpin">{{ __('Unpin') }}</button>
        @else
            <button type="button" wire:click="pin({{ $id }})" class="{{ $buttonClass }}" data-test="pin">{{ __('Pin') }}</button>
        @endif
    @endif
    @if ($canModerate && $published && ! ($mine ?? false))
        <button type="button" wire:click="startModeration('hide', '{{ $type }}', {{ $id }}, '{{ $where }}')" class="{{ $buttonClass }}" data-test="hide">{{ __('Hide') }}</button>
    @endif
    @if ($canModerate && $status === \Modules\BuildingGroup\Enums\PostStatus::Hidden)
        <button type="button" wire:click="unhide('{{ $type }}', {{ $id }})" class="{{ $buttonClass }}" data-test="unhide">{{ __('Show again') }}</button>
    @endif
    @if ($canRemove && $status !== \Modules\BuildingGroup\Enums\PostStatus::Removed && ! ($mine ?? false))
        <button type="button" wire:click="startModeration('remove', '{{ $type }}', {{ $id }}, '{{ $where }}')" class="min-h-9 rounded-md px-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950" data-test="remove">{{ __('Remove') }}</button>
    @endif
    @if ($canMute && $fromTenant)
        <button type="button" wire:click="startModeration('mute', '{{ $type }}', {{ $id }}, '{{ $where }}')" class="{{ $buttonClass }}" data-test="mute">{{ __('Mute author') }}</button>
    @endif
    @if ($canModerate && $openReports > 0)
        <button type="button" wire:click="dismissReports('{{ $type }}', {{ $id }})" wire:confirm="{{ __('Dismiss the reports and leave it as it is?') }}" class="{{ $buttonClass }}" data-test="dismiss-reports">{{ __('Dismiss reports') }}</button>
    @endif
</div>
