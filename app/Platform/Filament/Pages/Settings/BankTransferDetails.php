<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Settings\SettingsStore;
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
use UnitEnum;

/**
 * Settings → Bank transfer details (P06-04; BILLING_PAYMENTS.md § 5 step 1:
 * "Invoice shows GoProperty's settlement account"): the bank, account name
 * and 10-digit account number customers pay GoProperty's invoices into,
 * with the invoice number as the narration. Edited by `finance-admin`
 * (`plans.manage`); every change is audited by the settings store. Until
 * all three are set, invoices offer no bank transfer. Clearing them takes
 * the offer away; proofs already sent stay for review.
 *
 * @property-read Schema $form
 */
final class BankTransferDetails extends Page
{
    /** @var array<string, string> form field => setting key */
    private const array KEYS = [
        'bank' => 'billing.transfer_bank',
        'account_name' => 'billing.transfer_account_name',
        'account_number' => 'billing.transfer_account_number',
    ];

    protected static ?string $slug = 'settings/bank-transfer';

    protected static ?string $title = 'Bank transfer details';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('plans.manage');
    }

    public function getSubheading(): string
    {
        return 'GoProperty\'s own account, shown on every open subscription invoice with the invoice number as the narration. Customers then send a proof, which staff confirm under Billing → Bank transfers.';
    }

    public function mount(): void
    {
        $settings = app(SettingsStore::class);
        $values = [];

        foreach (self::KEYS as $field => $key) {
            $value = $settings->get($key);
            $values[$field] = is_string($value) ? $value : '';
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Account')->columns(3)->schema([
                TextInput::make('bank')->label('Bank')->maxLength(80),
                TextInput::make('account_name')->label('Account name')->maxLength(120),
                TextInput::make('account_number')->label('Account number')->maxLength(10)->helperText('10 digits (NUBAN).'),
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
        $values = [];

        foreach (self::KEYS as $field => $key) {
            $value = trim((string) ($state[$field] ?? ''));

            try {
                $values[$key] = $settings->validated($key, $value === '' ? null : $value);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['data.'.$field => match ($field) {
                    'account_number' => 'The account number is 10 digits.',
                    default => 'Use plain text, without < or >.',
                }]);
            }
        }

        $set = array_filter($values, static fn (mixed $value): bool => $value !== null);

        if ($set !== [] && count($set) !== count($values)) {
            throw ValidationException::withMessages(['data.bank' => 'Give the bank, the account name and the account number, or clear all three.']);
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

        Notification::make()->title($changed === 0 ? 'Nothing changed' : ($set === [] ? 'Saved: invoices no longer offer a bank transfer' : 'Saved: open invoices show this account'))->success()->send();
    }
}
