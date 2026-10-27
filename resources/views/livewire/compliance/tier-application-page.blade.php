{{--
    Transaction Tier application (ONBOARDING_COMPLIANCE.md § 5.3), org-owner
    only: start, upload documents, Tier 3 compliance contact, submit, see
    compliance's requests and answer them. App\Livewire\Compliance\TierApplicationPage.
--}}
@php
    use App\Enums\TierApplicationStatus;

    $statusColor = fn (TierApplicationStatus $status): string => match ($status) {
        TierApplicationStatus::Approved => 'green',
        TierApplicationStatus::Rejected => 'red',
        TierApplicationStatus::MoreInfoNeeded => 'amber',
        TierApplicationStatus::Draft => 'zinc',
        default => 'blue',
    };
    $open = $application !== null && $application->status->isOpen();
@endphp

<x-page :heading="__('Transaction Tier')" :description="__(':name is at :tier. Your tier sets how much rent you can manage here; tenants always pay you directly.', ['name' => $organization->name, 'tier' => $organization->transaction_tier->label()])">
    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    @error('application')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" data-test="refusal" />
    @enderror

    @if ($application !== null && $application->status->isDecided())
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="last-decision">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" level="2">{{ __('Your application for :tier', ['tier' => $application->requested_tier->label()]) }}</flux:heading>
                <flux:badge size="sm" :color="$statusColor($application->status)">{{ $application->status->label() }}</flux:badge>
            </div>
            <flux:text class="mt-2">
                @if ($application->status === TierApplicationStatus::Approved)
                    {{ __('Approved on :date. Your organization is now at :tier.', ['date' => $application->decided_at?->format('j M Y'), 'tier' => $application->requested_tier->label()]) }}
                @else
                    {{ __('Not approved (:date).', ['date' => $application->decided_at?->format('j M Y')]) }}
                    @if ($application->review_note) {{ __('Compliance said: :note', ['note' => $application->review_note]) }} @endif
                @endif
            </flux:text>
        </section>

        @if ($application->status === TierApplicationStatus::Approved)
            @include('livewire.compliance.partials.documents')
        @endif
    @endif

    @if (! $open)
        @if ($tierOptions === [])
            <flux:text>{{ __('You are at the highest tier.') }}</flux:text>
        @elseif ($canStart)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="start">
                <flux:heading size="lg" level="2">{{ __('Apply for a higher tier') }}</flux:heading>
                <flux:text class="mt-1">{{ __('You upload your documents and compliance reviews them. Until it is approved you keep working at your current tier, so nothing stops in the meantime.') }}</flux:text>
                <form wire:submit="start" class="mt-4 flex flex-col gap-4">
                    <flux:radio.group wire:model="tier" :label="__('Tier')">
                        @foreach ($tierOptions as $option)
                            <flux:radio :value="$option->tier->value" :label="$option->tier->label().' — '.$option->name" :description="$option->suits.'. '.$option->activation.'.'" />
                        @endforeach
                    </flux:radio.group>
                    @error('tier') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
                    <div><flux:button type="submit" variant="primary">{{ __('Start application') }}</flux:button></div>
                </form>
            </section>
        @endif
    @else
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="application" data-status="{{ $application->status->value }}">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" level="2">{{ __('Applying for :tier', ['tier' => $application->requested_tier->label()]) }}</flux:heading>
                <flux:badge size="sm" :color="$statusColor($application->status)">{{ $application->status->label() }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                @if ($application->status->isWithCompliance())
                    {{ __('Submitted :date. Compliance is reviewing it; we will email you when they decide or need anything.', ['date' => $application->submitted_at?->format('j M Y')]) }}
                @else
                    {{ __('Upload every required document, then submit. Until it is approved you work at :tier limits.', ['tier' => $application->from_tier->label()]) }}
                @endif
            </flux:text>

            @if ($application->status === TierApplicationStatus::MoreInfoNeeded && $application->review_note)
                <flux:callout class="mt-4" variant="warning" icon="chat-bubble-left-ellipsis" :heading="__('Compliance needs more information')" data-test="more-info">
                    <flux:callout.text>{{ $application->review_note }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($application->status === TierApplicationStatus::Draft && $canEdit && count($tierOptions) > 1)
                <form wire:submit="changeTier" class="mt-4 flex flex-wrap items-end gap-3">
                    <flux:select wire:model="tier" :label="__('Tier')" class="max-w-56">
                        @foreach ($tierOptions as $option)
                            <option value="{{ $option->tier->value }}">{{ $option->tier->label() }} — {{ $option->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit">{{ __('Change tier') }}</flux:button>
                </form>
            @endif
        </section>

        @include('livewire.compliance.partials.documents')

        @if ($needsContact)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="contact">
                <flux:heading size="lg" level="2">{{ __('Compliance contact') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The person compliance can reach about anti-money-laundering matters.') }}</flux:text>
                @if ($canEdit)
                    <form wire:submit="saveContact" class="mt-4 grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="contactName" :label="__('Name')" />
                        <flux:input type="email" wire:model="contactEmail" :label="__('Email')" />
                        <flux:input type="tel" wire:model="contactPhone" :label="__('Phone number')" />
                        <div class="sm:col-span-3"><flux:button type="submit">{{ __('Save contact') }}</flux:button></div>
                    </form>
                @else
                    <flux:text class="mt-2">{{ $contactName }} · {{ $contactEmail }} · {{ $contactPhone }}</flux:text>
                @endif
            </section>
        @endif

        @if ($application->status->isWithApplicant())
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="submit">
                <flux:heading size="lg" level="2">{{ __('Submit for review') }}</flux:heading>
                @php $missing = $problems !== [] ? $problems : $outstanding; @endphp
                @if ($missing !== [])
                    <flux:text class="mt-1">{{ __('Still needed before compliance can review it:') }}</flux:text>
                    <ul class="mt-2 list-disc ps-5 text-sm text-zinc-700 dark:text-zinc-300" data-test="outstanding">
                        @foreach ($missing as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                @else
                    <flux:text class="mt-1">{{ __('Everything required is here.') }}</flux:text>
                @endif
                @if ($canEdit)
                    <form wire:submit="submit" class="mt-4 flex flex-col gap-3">
                        @if ($application->status === TierApplicationStatus::MoreInfoNeeded)
                            <flux:textarea wire:model="note" :label="__('Your answer to compliance')" rows="3" />
                        @endif
                        @error('submit') <flux:text class="text-red-600" data-test="submit-error">{{ $message }}</flux:text> @enderror
                        <div><flux:button type="submit" variant="primary">{{ $application->status === TierApplicationStatus::MoreInfoNeeded ? __('Send back to compliance') : __('Submit application') }}</flux:button></div>
                    </form>
                @endif
            </section>
        @endif
    @endif

</x-page>
