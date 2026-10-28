{{--
    An agency's header on the tenant portal's repair pages (UX_DESIGN.md
    § 2: each agency in its own colours, as the portal's other pages).
    $name, $colour, $initials, $organizationId, $readOnly.
--}}
<header class="flex min-w-0 items-center gap-3" data-test="portal-agency" data-organization="{{ $organizationId }}">
    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white" style="background-color: {{ $colour }}" aria-hidden="true">{{ $initials }}</span>
    <div class="min-w-0">
        <flux:heading size="lg" level="2" class="break-words">{{ $name }}</flux:heading>
        @if ($readOnly)
            <flux:text class="text-sm" data-test="portal-read-only">{{ __('Read-only for now: :agency\'s account is on hold, so new problems cannot be reported here. Call them if it is urgent.', ['agency' => $name]) }}</flux:text>
        @endif
    </div>
</header>
