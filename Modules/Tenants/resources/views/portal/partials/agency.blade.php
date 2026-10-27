{{--
    An agency's header in the tenant portal (P03-11a; UX_DESIGN.md § 2: each
    agency in its own colours). $agency: Portal\AgencyRentals; $brand
    (optional): a bill's branding as issued (App\Documents\DocumentBranding),
    whose logo is shown when it has one (a PNG data URI from the snapshot).
--}}
@php($brand ??= null)
<header class="flex min-w-0 items-center gap-3" data-test="portal-agency" data-organization="{{ $agency->organizationId() }}">
    @if ($brand?->logoSrc)
        <img src="{{ $brand->logoSrc }}" alt="{{ $agency->name }}" class="h-10 max-w-32 shrink-0 object-contain">
    @else
        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white" style="background-color: {{ $brand?->strong() ?? $agency->colour }}" aria-hidden="true">{{ $agency->initials }}</span>
    @endif
    <div class="min-w-0">
        <flux:heading size="lg" level="2" class="break-words">{{ $agency->name }}</flux:heading>
        @if ($agency->readOnly())
            <flux:text class="text-sm" data-test="portal-read-only">{{ __('Read-only for now: :agency\'s account is on hold. You can still see your records, pay by bank transfer and send proofs of payment.', ['agency' => $agency->name]) }}</flux:text>
        @endif
    </div>
</header>
