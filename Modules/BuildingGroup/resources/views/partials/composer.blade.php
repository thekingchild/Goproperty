{{--
    The post form (P07-02): rich text and, when the group takes photos and
    the person may add them, up to 4 photos (JPEG, PNG or WebP, 2 MB each);
    the server checks every file from its bytes again. $canAttach.
--}}
<form wire:submit="publish" class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="post-form">
    @include('buildinggroup::partials.rich-editor', ['model' => 'body', 'id' => 'group-post-body', 'label' => __('Write a post'), 'placeholder' => __('Share news or ask your neighbours…')])
    @error('body')
        <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="post-error">{{ $message }}</flux:text>
    @enderror

    @if ($canAttach)
        <div class="flex flex-col gap-1">
            <label class="flex min-h-11 cursor-pointer flex-col gap-2">
                <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Photos (optional, up to :count, :size each)', ['count' => \Modules\BuildingGroup\Posts\GroupPosts::MAX_IMAGES, 'size' => '2 MB']) }}</span>
                <input type="file" wire:model="images" multiple accept="image/jpeg,image/png,image/webp"
                    class="block min-h-11 w-full max-w-full rounded-lg border border-zinc-200 bg-white text-sm text-zinc-700 file:me-3 file:min-h-11 file:border-0 file:bg-zinc-100 file:px-4 file:font-medium file:text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-100"
                    data-test="post-images-input">
            </label>
            <div wire:loading wire:target="images" class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Uploading…') }}</div>
            @if ($images !== [])
                <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300" data-test="post-images-chosen">
                    <span>{{ trans_choice('One photo chosen|:count photos chosen', count($images), ['count' => count($images)]) }}</span>
                    <button type="button" wire:click="clearImages" class="min-h-9 rounded-md px-2 font-medium text-zinc-700 underline hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800" data-test="clear-images">{{ __('Remove photos') }}</button>
                </div>
            @endif
            @error('images')
                <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="post-images-error">{{ $message }}</flux:text>
            @enderror
            @error('images.*')
                <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert" data-test="post-images-error">{{ $message }}</flux:text>
            @enderror
        </div>
    @endif

    <div>
        <flux:button type="submit" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" wire:loading.attr="disabled" wire:target="publish,images" data-test="publish-post">{{ __('Post') }}</flux:button>
    </div>
</form>
