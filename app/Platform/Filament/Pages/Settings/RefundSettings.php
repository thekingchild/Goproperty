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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;
use UnitEnum;

/**
 * Settings → Refunds (P06-07b; PLATFORM_CONSOLE.md § 1 two-person rules:
 * "Refunds above a set amount (e.g. ₦100,000)"; docs/plans/P06-subtasks.md
 * default 57): the amount above which a refund (counting the payment's
 * earlier refunds) waits for a second staff member's approval. Only
 * `refunds.configure` (super-admin by default: the people who make refunds
 * do not set their own limit). Audited by the settings store.
 *
 * @property-read Schema $form
 */
final class RefundSettings extends Page
{
    private const string KEY = 'billing.refund_approval_above_kobo';

    protected static ?string $slug = 'settings/refunds';

    protected static ?string $title = 'Refund approval';

    protected static ?string $navigationLabel = 'Refunds';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('refunds.configure');
    }

    public function getSubheading(): string
    {
        return 'Refunds of GoProperty\'s own income up to this amount run at once; above it a second staff member with "Approve refunds" must approve them in Staff → Approvals. It counts what the payment already had refunded, so splitting a refund never avoids the approval.';
    }

    public function mount(): void
    {
        $value = app(SettingsStore::class)->get(self::KEY);

        $this->form->fill(['above' => Money::kobo(is_int($value) ? $value : 10_000_000)->toDecimalString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Two-person rule')->schema([
                TextInput::make('above')->label('Approval needed above (₦)')->required()->maxLength(20)
                    ->helperText('0 means every refund needs approval.'),
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

        try {
            $kobo = Money::naira(trim((string) ($state['above'] ?? '')))->kobo;
            $value = app(SettingsStore::class)->validated(self::KEY, $kobo);
        } catch (InvalidArgumentException|OverflowException|ValidationException) {
            throw ValidationException::withMessages(['data.above' => 'Give an amount in naira from 0 to 1,000,000,000, such as 100,000.']);
        }

        $settings = app(SettingsStore::class);

        if ($settings->get(self::KEY) === $value) {
            Notification::make()->title('Nothing changed')->success()->send();

            return;
        }

        // Audited by the store.
        $settings->set(self::KEY, $value);

        Notification::make()->title('Saved: refunds above '.Money::kobo((int) $value)->format().' need a second staff member\'s approval')->success()->send();
    }
}
