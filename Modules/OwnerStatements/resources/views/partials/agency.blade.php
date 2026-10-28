{{--
    An agency's header on the owner portal's statement pages (P08-05; the
    owner portal's agency header, P03-15): its current logo, or its initials
    in its colour, and its name. $agency: Modules\OwnerStatements\Statements\PortalAgency.
--}}
<header class="flex min-w-0 items-center gap-3" data-test="owner-agency" data-organization="{{ $agency->organizationId }}">
    @if ($agency->logoSrc)
        <img src="{{ $agency->logoSrc }}" alt="{{ $agency->name }}" class="h-10 max-w-32 shrink-0 object-contain">
    @else
        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white" style="background-color: {{ $agency->colour }}" aria-hidden="true">{{ $agency->initials }}</span>
    @endif
    <div class="min-w-0">
        <flux:heading size="lg" level="2" class="break-words">{{ $agency->name }}</flux:heading>
        @isset($subtitle)
            <flux:text class="text-sm">{{ $subtitle }}</flux:text>
        @endisset
    </div>
</header>
