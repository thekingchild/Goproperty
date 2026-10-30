<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Settings\SettingsStore;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnitEnum;

/**
 * Settings → SMS credit prices (P05-03; BILLING_PAYMENTS.md § 1.2 "extra
 * SMS bundles are bought in-app, priced in the console", § 1.3: edited by
 * `finance-admin`, `plans.manage`): the price of one credit (one SMS page)
 * before VAT, and the bundles offered. Applies to purchases started after
 * the save; every change is audited by the settings store. The monthly
 * allowances are the plans' (config/plans.php until P06-01's plan editor),
 * and one customer's custom allowance is set on its page.
 *
 * @property-read Schema $form
 */
final class SmsCreditPrices extends Page
{
    protected static ?string $slug = 'settings/sms-credits';

    protected static ?string $title = 'SMS credit prices';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('plans.manage');
    }

    public function getSubheading(): string
    {
        return 'What agencies pay for SMS credits beyond their plan\'s monthly allowance. VAT is added on top.';
    }

    public function mount(): void
    {
        $settings = app(SettingsStore::class);
        $price = $settings->get('sms_credits.price_kobo');
        $bundles = $settings->get('sms_credits.bundles');

        $this->form->fill([
            'price' => Money::kobo(is_int($price) ? $price : 600)->toDecimalString(),
            'bundles' => implode(', ', array_filter(is_array($bundles) ? $bundles : [], 'is_int')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Bundles')->columns(2)->schema([
                TextInput::make('price')->label('Price per credit (one SMS page)')->prefix('₦')->required()->maxLength(12)
                    ->helperText('Before VAT, for example 6.00.'),
                TextInput::make('bundles')->label('Bundles, in credits')->required()->maxLength(100)
                    ->helperText('Up to 6, separated by commas, for example 500, 1000, 2500, 5000.'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->footer([Actions::make([$this->saveAction()])->sticky()->key('form-actions')]),
        ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')->label('Save')->action(fn () => $this->save());
    }

    private function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $state = $this->form->getState();
        $settings = app(SettingsStore::class);

        try {
            $price = Money::naira(trim((string) ($state['price'] ?? '')))->kobo;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['data.price' => 'Give the price in naira, for example 6.00.']);
        }

        $parts = array_map('trim', explode(',', (string) ($state['bundles'] ?? '')));
        $bundles = [];

        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                throw ValidationException::withMessages(['data.bundles' => 'List whole numbers of credits, separated by commas.']);
            }

            $bundles[] = (int) $part;
        }

        $values = ['sms_credits.price_kobo' => $price, 'sms_credits.bundles' => $bundles];

        foreach ($values as $key => $value) {
            try {
                $values[$key] = $settings->validated($key, $value);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    $key === 'sms_credits.price_kobo' ? 'data.price' : 'data.bundles' => $key === 'sms_credits.price_kobo'
                        ? 'The price per credit is between ₦0.01 and ₦1,000.'
                        : 'Give 1 to 6 different bundles of 10 to 100,000 credits.',
                ]);
            }
        }

        $changed = 0;

        DB::transaction(function () use ($values, $settings, &$changed): void {
            foreach ($values as $key => $value) {
                if ($settings->get($key) !== $value) {
                    // Audited by the store.
                    $settings->set($key, $value);
                    $changed++;
                }
            }
        });

        Notification::make()->title($changed === 0 ? 'Nothing changed' : 'Saved: new purchases use these prices')->success()->send();
    }
}
