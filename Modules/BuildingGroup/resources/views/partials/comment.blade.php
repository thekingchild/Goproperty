{{--
    A comment or a reply in the feed (P07-02). $comment (Feed\FeedComment),
    $postId, $canComment, $canReact, $isReply. P07-04: members may report
    it; moderators also get hidden and removed ones, marked, with their
    trail and actions.
--}}
@php
    $commentShown = $comment->status === \Modules\BuildingGroup\Enums\PostStatus::Published;
@endphp
<div class="flex flex-col gap-1" data-test="{{ $isReply ? 'reply' : 'comment' }}" data-comment="{{ $comment->id }}" data-status="{{ $comment->status->value }}" wire:key="comment-{{ $comment->id }}">
    <div @class(['rounded-xl px-3 py-2', 'bg-zinc-50 dark:bg-zinc-800/60' => $commentShown, 'border border-dashed border-amber-400 bg-amber-50/60 dark:border-amber-700 dark:bg-amber-950/30' => ! $commentShown])>
        <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span class="font-medium text-zinc-900 dark:text-white" data-test="author">{{ $comment->author }}</span>
            @if ($comment->badge)
                <flux:badge size="sm" color="zinc" data-test="author-badge">{{ $comment->badge }}</flux:badge>
            @endif
            <span class="text-zinc-500 dark:text-zinc-400">{{ \Modules\BuildingGroup\Feed\GroupDates::show($comment->at) }}</span>
            @unless ($commentShown)
                <flux:badge size="sm" color="amber" data-test="moderation-status">{{ $comment->status->label() }}</flux:badge>
            @endunless
            @if ($comment->openReports > 0)
                <flux:badge size="sm" color="red" data-test="open-reports">{{ trans_choice(':count report|:count reports', $comment->openReports, ['count' => $comment->openReports]) }}</flux:badge>
            @endif
        </div>
        @include('buildinggroup::partials.rich-text', ['html' => $comment->html])
        @if ($isModerator && $comment->trail !== [])
            <div class="mt-1" data-test="moderated-note">
                @include('buildinggroup::partials.trail', ['trail' => $comment->trail])
            </div>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2 ps-1">
        @include('buildinggroup::partials.reactions', ['type' => 'group_comment', 'id' => $comment->id, 'reactions' => $comment->reactions, 'canReact' => $canReact && $commentShown])
        @if ($canReport && $commentShown && ! $comment->mine)
            @include('buildinggroup::partials.report', ['type' => 'group_comment', 'id' => $comment->id, 'reportedByMe' => $comment->reportedByMe])
        @endif
        @if ($isModerator)
            @include('buildinggroup::partials.moderation-actions', ['type' => 'group_comment', 'id' => $comment->id, 'status' => $comment->status, 'pinned' => false, 'fromTenant' => $comment->fromTenant, 'openReports' => $comment->openReports, 'where' => 'feed', 'mine' => $comment->mine])
        @endif
        @if ($canComment && $commentShown)
            <button type="button" wire:click="startReply({{ $isReply ? $parentId : $comment->id }})" class="min-h-9 rounded-md px-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" data-test="start-reply">{{ __('Reply') }}</button>
        @endif
        @if ($comment->canDelete)
            <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Remove your comment? Replies under it go too.') }}" class="min-h-9 rounded-md px-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950" data-test="delete-comment">{{ __('Remove') }}</button>
        @endif
    </div>
    @if ($isModerator)
        @include('buildinggroup::partials.moderation-form', ['key' => 'group_comment:'.$comment->id, 'where' => 'feed'])
    @endif
</div>
