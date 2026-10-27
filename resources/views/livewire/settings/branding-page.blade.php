{{--
    Settings → Branding (App\Livewire\Settings\BrandingPage, BRANDING_DOCUMENTS.md § 1).
    The preview iframes hold the very HTML a PDF would get (srcdoc, no
    scripts), rebuilt from the unsaved form on every change.
--}}
@php
    $fieldsDisabled = ! $canEdit;
@endphp

<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.branding" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <form wire:submit="save" class="flex min-w-0 flex-col gap-6" data-test="branding-form">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Name and logo') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Company name: :name (change it under Profile).', ['name' => $organization->name]) }}</flux:text>

                <div class="mt-4 grid gap-5">
                    <flux:input wire:model.live.debounce.400ms="tradingName" :label="__('Trading name (optional)')" :description="__('Shown in headers and as the email sender, e.g. “Adeyemi Realty” for “Adeyemi Realty Ltd”.')" maxlength="120" :disabled="$fieldsDisabled" />

                    @foreach (['logo' => __('Logo'), 'signature' => __('Signature (optional)'), 'stamp' => __('Stamp (optional)')] as $role => $label)
                        @php $removeProperty = 'remove'.ucfirst($role); @endphp
                        <div class="flex flex-col gap-2" data-test="upload-{{ $role }}">
                            <flux:label>{{ $label }}</flux:label>
                            <div class="flex flex-wrap items-center gap-3">
                                @if ($imageUrls[$role] && ! $this->{$removeProperty})
                                    <img src="{{ $imageUrls[$role] }}" alt="" class="h-12 max-w-40 rounded border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-700">
                                @endif
                                @if ($canEdit)
                                    <input type="file" wire:model="{{ $role }}" accept="image/png,image/jpeg,image/webp" class="block text-sm text-zinc-700 file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800">
                                    @if ($imageUrls[$role])
                                        <flux:checkbox wire:model.live="{{ $removeProperty }}" :label="__('Remove')" />
                                    @endif
                                @endif
                            </div>
                            <flux:text class="text-sm">
                                {{ $role === 'logo'
                                    ? __('PNG, JPEG or WebP, up to 1 MB, at least 300 px wide. Without one, your initials are used.')
                                    : __('PNG, JPEG or WebP, up to 1 MB. A transparent background looks best on the signature line.') }}
                            </flux:text>
                            <flux:error :name="$role" />
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Colours') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The primary colour is the brand bar, table headers and the amount-due panel. It must stand out against white paper.') }}</flux:text>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    @foreach (['primaryColor' => __('Primary colour'), 'accentColor' => __('Accent colour (optional)')] as $field => $label)
                        <div class="flex flex-col gap-2">
                            <flux:label>{{ $label }}</flux:label>
                            <div class="flex items-center gap-2">
                                <input type="color" wire:model.live="{{ $field }}" value="{{ \App\Theme\Color::isHex($this->{$field}) ? \App\Theme\Color::normalize($this->{$field}) : '#000000' }}" class="h-10 w-12 cursor-pointer rounded border border-zinc-200 bg-white p-1 dark:border-zinc-700" aria-label="{{ $label }}" @disabled($fieldsDisabled)>
                                <flux:input wire:model.live.debounce.500ms="{{ $field }}" maxlength="7" class="font-mono" placeholder="#0E6B5C" :disabled="$fieldsDisabled" />
                            </div>
                            @if (isset($colourProblems[$field]) && ! $errors->has($field))
                                <flux:text class="text-sm text-amber-700 dark:text-amber-400" data-test="contrast-warning-{{ $field }}">{{ $colourProblems[$field] }}</flux:text>
                            @endif
                            <flux:error :name="$field" />
                        </div>
                    @endforeach
                </div>
                @if (! isset($colourProblems['primaryColor']) && $primaryContrast < \App\Branding\BrandColours::TEXT_AGAINST_WHITE)
                    <flux:text class="mt-3 text-sm" data-test="text-shade">{{ __('White text on this colour reaches :ratio:1, so documents use the darker shade :shade for text and panels.', ['ratio' => $primaryContrast, 'shade' => $textShade]) }}</flux:text>
                @endif
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Letterhead') }}</flux:heading>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model.live.debounce.400ms="address" :label="__('Address')" rows="2" maxlength="300" :disabled="$fieldsDisabled" />
                    </div>
                    <flux:input wire:model.live.debounce.400ms="rcNumber" :label="__('RC number')" placeholder="RC 1234567" maxlength="20" :disabled="$fieldsDisabled" />
                    <flux:input wire:model.live.debounce.400ms="phone" type="tel" :label="__('Phone')" placeholder="+234 803 000 0000" maxlength="30" :disabled="$fieldsDisabled" />
                    <flux:input wire:model.live.debounce.400ms="email" type="email" :label="__('Email')" placeholder="accounts@example.ng" maxlength="254" :disabled="$fieldsDisabled" />
                    <flux:input wire:model.live.debounce.400ms="website" :label="__('Website')" placeholder="example.ng" maxlength="120" :disabled="$fieldsDisabled" />
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Documents') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Prefixes start each document number, e.g. :example.', ['example' => $invoicePrefix.'-'.now()->year.'-000142']) }}</flux:text>

                <div class="mt-4 grid gap-5 sm:grid-cols-3">
                    <flux:input wire:model.live.debounce.400ms="invoicePrefix" :label="__('Invoice prefix')" maxlength="20" class="uppercase" :disabled="$fieldsDisabled" />
                    <flux:input wire:model.live.debounce.400ms="receiptPrefix" :label="__('Receipt prefix')" maxlength="20" class="uppercase" :disabled="$fieldsDisabled" />
                    <flux:input wire:model.live.debounce.400ms="leasePrefix" :label="__('Lease prefix')" maxlength="20" class="uppercase" :disabled="$fieldsDisabled" />
                </div>

                <div class="mt-5 grid gap-5">
                    <flux:radio.group wire:model.live="layout" :label="__('Layout')" :disabled="$fieldsDisabled">
                        @foreach ($layouts as $option)
                            <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" />
                        @endforeach
                    </flux:radio.group>

                    <flux:textarea wire:model.live.debounce.500ms="invoiceNotes" :label="__('Invoice notes / terms')" rows="3" :maxlength="$notesMax" :description="__('Plain text, up to :max characters.', ['max' => $notesMax])" :disabled="$fieldsDisabled" />
                    <flux:textarea wire:model.live.debounce.500ms="receiptFooter" :label="__('Receipt footer')" rows="2" :maxlength="$footerMax" :description="__('Plain text, up to :max characters.', ['max' => $footerMax])" :disabled="$fieldsDisabled" />
                    <flux:input wire:model.live.debounce.400ms="emailSenderName" :label="__('Email sender name')" :placeholder="($tradingName !== '' ? $tradingName : $organization->name).' via '.$productName" maxlength="60" :description="__('Replies go to your letterhead email.')" :disabled="$fieldsDisabled" />

                    @if ($mayHidePoweredBy)
                        <flux:switch wire:model.live="hidePoweredBy" :label="__('Hide the “Generated with :product” line', ['product' => $productName])" :disabled="$fieldsDisabled" />
                    @else
                        <flux:text class="text-sm">{{ __('Documents end with a small “Generated with :product” line. The Scale plan can remove it.', ['product' => $productName]) }}</flux:text>
                    @endif
                    <flux:error name="hidePoweredBy" />
                </div>
            </section>

            @if ($errors->any())
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Not saved: correct the fields marked above.')" data-test="not-saved" />
            @endif

            @if ($canEdit)
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Save branding') }}</flux:button>
                    <flux:text class="text-sm">{{ __('Nothing is saved until you press Save. Documents already issued keep the branding they were issued with.') }}</flux:text>
                </div>
            @else
                <flux:text>{{ __('You can see the branding but not change it.') }}</flux:text>
            @endif
        </form>

        <section class="min-w-0 lg:sticky lg:top-6 lg:self-start" aria-label="{{ __('Preview') }}" data-test="preview">
            <div class="mb-3 flex items-center justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __('Preview') }} <flux:badge size="sm" color="zinc">{{ __('Sample') }}</flux:badge></flux:heading>
                <flux:radio.group wire:model.live="previewing" variant="segmented" size="sm">
                    <flux:radio value="invoice" :label="__('Invoice')" />
                    <flux:radio value="receipt" :label="__('Receipt')" />
                </flux:radio.group>
            </div>

            {{-- Scaled to the column; the documents are A4 at 794 × 1123 px. The
                 frames keep Alpine's sizing across Livewire updates (wire:ignore.self). --}}
            <div x-data="{ scale: 1 }" x-init="const fit = () => scale = Math.min(1, $el.clientWidth / 794); fit(); new ResizeObserver(fit).observe($el)"
                 wire:ignore.self x-bind:style="{ height: Math.ceil(1123 * scale) + 'px' }"
                 class="relative w-full overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-700">
                <div wire:ignore.self x-bind:style="{ transform: 'scale(' + scale + ')' }" class="absolute left-0 top-0 h-[1123px] w-[794px] origin-top-left bg-white">
                    @foreach (['invoice' => $invoiceHtml, 'receipt' => $receiptHtml] as $kind => $html)
                        <iframe title="{{ $kind === 'invoice' ? __('Sample invoice') : __('Sample receipt') }}" sandbox="allow-same-origin" srcdoc="{{ $html }}"
                                @class(['block h-full w-full border-0', 'hidden' => $previewing !== $kind]) data-test="preview-{{ $kind }}"></iframe>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
</x-page>
