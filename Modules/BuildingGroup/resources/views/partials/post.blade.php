{{--
    A post in the feed (P07-02): author, time, words, photos (thumbnails
    linking to the large version; hidden while GoProperty staff
    impersonate), reactions, comments with their replies and the comment
    box. $post (Feed\FeedPost), $showImages, $canComment, $canReact,
    $replyingTo.
--}}
<article class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="post" data-post="{{ $post->id }}" wire:key="post-{{ $post->id }}">
    <header class="flex items-start justify-between gap-2">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2">
            <span class="font-semibold text-zinc-900 dark:text-white" data-test="author">{{ $post->author }}</span>
            @if ($post->badge)
                <flux:badge size="sm" color="zinc" data-test="author-badge">{{ $post->badge }}</flux:badge>
            @endif
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ \Modules\BuildingGroup\Feed\GroupDates::show($post->at) }}</span>
        </div>
        @if ($post->canDelete)
            <button type="button" wire:click="deletePost({{ $post->id }})" wire:confirm="{{ __('Remove your post? Its photos are deleted for good.') }}" class="min-h-9 shrink-0 rounded-md px-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950" data-test="delete-post">{{ __('Remove') }}</button>
        @endif
    </header>

    @if ($post->html)
        @include('buildinggroup::partials.rich-text', ['html' => $post->html])
    @endif

    @if ($post->imageCount > 0)
        @if ($showImages)
            <div @class(['grid gap-2', 'grid-cols-1' => count($post->images) === 1, 'grid-cols-2' => count($post->images) > 1]) data-test="post-images">
                @foreach ($post->images as $image)
                    <a href="{{ $image->fullUrl }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        <img src="{{ $image->thumbUrl }}" alt="{{ __('Photo :n of the post', ['n' => $loop->iteration]) }}" loading="lazy" class="aspect-square w-full object-cover" data-test="post-image">
                    </a>
                @endforeach
            </div>
        @else
            <flux:text class="text-sm" data-test="post-images-hidden">{{ trans_choice('One photo, not shown during a support session.|:count photos, not shown during a support session.', $post->imageCount, ['count' => $post->imageCount]) }}</flux:text>
        @endif
    @endif

    @include('buildinggroup::partials.reactions', ['type' => 'group_post', 'id' => $post->id, 'reactions' => $post->reactions, 'canReact' => $canReact])

    @if ($post->comments !== [])
        <div class="flex flex-col gap-3 border-t border-zinc-100 pt-3 dark:border-zinc-800" data-test="comments" data-count="{{ $post->commentCount }}">
            @foreach ($post->comments as $comment)
                @include('buildinggroup::partials.comment', ['comment' => $comment, 'postId' => $post->id, 'isReply' => false, 'parentId' => null])
                @if ($comment->replies !== [] || $replyingTo === $comment->id)
                    <div class="ms-6 flex flex-col gap-3 border-s border-zinc-200 ps-3 dark:border-zinc-700">
                        @foreach ($comment->replies as $reply)
                            @include('buildinggroup::partials.comment', ['comment' => $reply, 'postId' => $post->id, 'isReply' => true, 'parentId' => $comment->id])
                        @endforeach
                        @if ($canComment && $replyingTo === $comment->id)
                            <form wire:submit="reply({{ $post->id }}, {{ $comment->id }})" class="flex flex-col gap-2" data-test="reply-form" wire:key="reply-form-{{ $comment->id }}">
                                @include('buildinggroup::partials.rich-editor', ['model' => 'replies.'.$comment->id, 'id' => 'group-reply-'.$comment->id, 'label' => __('Reply to :name', ['name' => $comment->author]), 'placeholder' => __('Write a reply…'), 'compact' => true])
                                @error('replies.'.$comment->id)
                                    <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="reply-error">{{ $message }}</flux:text>
                                @enderror
                                <div class="flex gap-2">
                                    <flux:button type="submit" size="sm" variant="primary" class="min-h-11" data-test="send-reply">{{ __('Reply') }}</flux:button>
                                    <flux:button type="button" size="sm" variant="ghost" class="min-h-11" wire:click="cancelReply">{{ __('Cancel') }}</flux:button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if ($canComment)
        <div x-data="{ open: false }" class="flex flex-col gap-2">
            <button type="button" x-show="! open" x-on:click="open = true" class="min-h-11 rounded-lg border border-dashed border-zinc-300 px-3 text-start text-sm text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800" data-test="open-comment">{{ __('Write a comment…') }}</button>
            <form x-show="open" x-cloak wire:submit="comment({{ $post->id }})" class="flex flex-col gap-2" data-test="comment-form">
                @include('buildinggroup::partials.rich-editor', ['model' => 'comments.'.$post->id, 'id' => 'group-comment-'.$post->id, 'label' => __('Your comment'), 'placeholder' => __('Write a comment…'), 'compact' => true])
                <div>
                    <flux:button type="submit" size="sm" variant="primary" class="min-h-11" data-test="send-comment">{{ __('Comment') }}</flux:button>
                </div>
            </form>
            @error('comments.'.$post->id)
                <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="comment-error">{{ $message }}</flux:text>
            @enderror
        </div>
    @endif
</article>
