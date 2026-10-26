{{-- Generated logo mark (PLATFORM_CONSOLE.md § 9.2 default): theme colours, no image. --}}
@props(['tone' => 'brand'])
<svg viewBox="0 0 34 34" aria-hidden="true" {{ $attributes->merge(['class' => 'size-8 shrink-0']) }}>
    <rect width="34" height="34" rx="9" fill="{{ $tone === 'light' ? '#fff' : 'var(--color-primary-700)' }}" />
    <path d="M9 16.2 17 10l8 6.2V25a1 1 0 0 1-1 1h-4.5v-5.5h-5V26H10a1 1 0 0 1-1-1z" fill="{{ $tone === 'light' ? 'var(--color-primary-700)' : '#fff' }}" />
    <circle cx="24.5" cy="10.5" r="3" fill="var(--color-brand-accent)" />
</svg>
