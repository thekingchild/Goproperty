{{--
    A group's feed (P07-02), shared by the workspace's and the portal's
    pages: the notice of the last action, a refusal, the post form when the
    person may post, then the posts and "Show more". The variables are the
    pages' (posts, more, showImages, canPost, canAttach, canComment,
    canReact, notice, replyingTo, live).

    Live updates (P07-03): Alpine `groupLive` (resources/js/app.js) calls
    refreshFeed every $live['poll'] seconds while the tab is shown and the
    person active, and on a VPS when Reverb says the group changed.
--}}
<div class="flex flex-col gap-4" data-test="group-feed" x-data="groupLive(@js($live))" data-live-poll="{{ $live['poll'] }}" @if ($live['websocket'] !== null) data-live-channel="{{ $live['websocket']['channel'] }}" @endif>
    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="group-notice" />
    @endif

    @error('group')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="group-warning" />
    @enderror

    @if ($canPost)
        @include('buildinggroup::partials.composer', ['canAttach' => $canAttach])
    @endif

    @if ($posts === [])
        <x-empty-state icon="chat-bubble-left-right" :heading="__('No posts yet')" :description="$canPost ? __('Start the conversation: share news, ask a question, say hello.') : __('Posts will show here.')" data-test="feed-empty" />
    @else
        @foreach ($posts as $post)
            @include('buildinggroup::partials.post', ['post' => $post, 'showImages' => $showImages, 'canComment' => $canComment, 'canReact' => $canReact, 'replyingTo' => $replyingTo])
        @endforeach

        @if ($more)
            <flux:button wire:click="showMore" class="min-h-11 w-full" data-test="show-more">{{ __('Show more') }}</flux:button>
        @endif
    @endif
</div>
