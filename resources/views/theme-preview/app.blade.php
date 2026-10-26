{{--
    Console preview of the customer app frame with the draft theme
    (PLATFORM_CONSOLE.md § 9.8). Sample content only: no customer data.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Theme preview')])
    </head>
    <body class="min-h-screen bg-white text-zinc-900">
        <div class="bg-brand-accent-50 px-4 py-2 text-center text-sm font-medium text-brand-accent-700" role="status" data-test="preview-banner">
            {{ $draft ? __('Preview of the draft theme. Visitors still see the published one.') : __('There is no draft: this is the published theme.') }}
        </div>

        <div class="flex min-h-[calc(100vh-2.25rem)]">
            <aside class="hidden w-64 shrink-0 flex-col gap-4 border-e border-zinc-200 bg-zinc-50 p-4 lg:flex">
                <x-app-logo />
                <nav class="flex flex-col gap-1 text-sm">
                    <span class="rounded-lg bg-primary-50 px-3 py-2 font-medium text-primary-700">{{ __('Today') }}</span>
                    <span class="px-3 py-2 text-zinc-600">{{ __('Properties') }}</span>
                    <span class="px-3 py-2 text-zinc-600">{{ __('Bills') }}</span>
                    <span class="px-3 py-2 text-zinc-600">{{ __('Payments') }}</span>
                </nav>
            </aside>

            <main class="flex-1 p-6 lg:p-10">
                <x-page :heading="__('Today')" :description="__('Sample workspace page.')">
                    <x-slot:actions>
                        <flux:button variant="primary">{{ __('Record a payment') }}</flux:button>
                        <flux:button>{{ __('Export') }}</flux:button>
                    </x-slot:actions>

                    <div class="grid gap-4 md:grid-cols-3">
                        @foreach ([['label' => __('To confirm'), 'value' => '4'], ['label' => __('Overdue bills'), 'value' => '2'], ['label' => __('Collected this month'), 'value' => \App\Support\Money::naira(1_250_000)->format()]] as $kpi)
                            <div class="rounded-xl border border-zinc-200 p-4">
                                <flux:text>{{ $kpi['label'] }}</flux:text>
                                <p class="mt-1 text-2xl font-bold text-primary-900">{{ $kpi['value'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:badge color="green">{{ __('Paid') }}</flux:badge>
                        <flux:badge color="amber">{{ __('Due soon') }}</flux:badge>
                        <flux:badge color="red">{{ __('Overdue') }}</flux:badge>
                        <span class="rounded-full bg-brand-accent px-3 py-1 text-xs font-semibold text-zinc-900">{{ __('Accent') }}</span>
                    </div>

                    <x-empty-state icon="inbox" :heading="__('Empty state')" :description="__('Lists without records show this, with a clear next step.')">
                        <x-slot:action>
                            <flux:button variant="primary" size="sm">{{ __('Add a property') }}</flux:button>
                        </x-slot:action>
                    </x-empty-state>
                </x-page>
            </main>
        </div>

        @fluxScripts
    </body>
</html>
