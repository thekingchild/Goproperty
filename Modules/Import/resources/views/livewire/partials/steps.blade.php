{{-- The import steps (DATA_IMPORT.md § 2): upload → map → dry run → review → commit → report. --}}
@props(['current'])

@php
    $steps = ['upload' => __('Upload'), 'map' => __('Map columns'), 'dry-run' => __('Dry run'), 'review' => __('Review'), 'commit' => __('Commit'), 'report' => __('Report')];
    $reached = array_search($current, array_keys($steps), true);
@endphp

<ol class="flex flex-wrap gap-x-4 gap-y-2 text-sm" aria-label="{{ __('Import steps') }}" data-test="import-steps">
    @foreach ($steps as $key => $label)
        @php $position = array_search($key, array_keys($steps), true); @endphp
        <li class="flex items-center gap-2 {{ $position === $reached ? 'font-semibold text-zinc-900 dark:text-white' : ($position < $reached ? 'text-zinc-600 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-500') }}" @if ($position === $reached) aria-current="step" @endif>
            <span class="flex size-6 items-center justify-center rounded-full border text-xs {{ $position <= $reached ? 'border-primary-600 bg-primary-600 text-white' : 'border-zinc-300 dark:border-zinc-600' }}">{{ $position + 1 }}</span>
            {{ $label }}
        </li>
    @endforeach
</ol>
