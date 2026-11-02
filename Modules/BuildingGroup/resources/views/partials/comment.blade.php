{{--
    A comment or a reply in the feed (P07-02). $comment (Feed\FeedComment),
    $postId, $canComment, $canReact, $isReply.
--}}
<div class="flex flex-col gap-1" data-test="{{ $isReply ? 'reply' : 'comment' }}" data-comment="{{ $comment->id }}" wire:key="comment-{{ $comment->id }}">
    <div class="rounded-xl bg-zinc-50 px-3 py-2 dark:bg-zinc-800/60">
        <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span class="font-medium text-zinc-900 dark:text-white" data-test="author">{{ $comment->author }}</span>
            @if ($comment->badge)
                <flux:badge size="sm" color="zinc" data-test="author-badge">{{ $comment->badge }}</flux:badge>
            @endif
            <span class="text-zinc-500 dark:text-zinc-400">{{ \Modules\BuildingGroup\Feed\GroupDates::show($comment->at) }}</span>
        </div>
        @include('buildinggroup::partials.rich-text', ['html' => $comment->html])
    </div>
    <div class="flex flex-wrap items-center gap-2 ps-1">
        @include('buildinggroup::partials.reactions', ['type' => 'group_comment', 'id' => $comment->id, 'reactions' => $comment->reactions, 'canReact' => $canReact])
        @if ($canComment)
            <button type="button" wire:click="startReply({{ $isReply ? $parentId : $comment->id }})" class="min-h-9 rounded-md px-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" data-test="start-reply">{{ __('Reply') }}</button>
        @endif
        @if ($comment->canDelete)
            <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Remove your comment? Replies under it go too.') }}" class="min-h-9 rounded-md px-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950" data-test="delete-comment">{{ __('Remove') }}</button>
        @endif
    </div>
</div>
