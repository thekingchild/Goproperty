{{--
    An agency's header in the owner portal (P03-15; UX_DESIGN.md § 2: each
    agency in its own colours): its current logo, or its initials in its
    colour, and its name. $agency: Modules\Properties\OwnerPortal\OwnerAgency.
--}}
<header class="flex min-w-0 items-center gap-3" data-test="owner-agency" data-organization="{{ $agency->organizationId }}">
    @if ($agency->logoSrc)
        <img src="{{ $agency->logoSrc }}" alt="{{ $agency->name }}" class="h-10 max-w-32 shrink-0 object-contain">
    @else
        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white" style="background-color: {{ $agency->colour }}" aria-hidden="true">{{ $agency->initials }}</span>
    @endif
    <div class="min-w-0">
        <flux:heading size="lg" level="2" class="break-words">{{ $agency->name }}</flux:heading>
        <flux:text class="text-sm">{{ __('Manages these buildings for you. You can look; only :agency changes them.', ['agency' => $agency->name]) }}</flux:text>
    </div>
</header>
