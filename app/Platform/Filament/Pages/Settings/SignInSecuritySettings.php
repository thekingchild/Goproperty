<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Models\PlatformStaff;
use App\Platform\Console\StepUp;
use App\Settings\SettingsStore;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Settings → Sign-in security (PLATFORM_CONSOLE.md § 9.6): passkeys,
 * Turnstile, lockout, "keep me signed in", trusted devices and who must use
 * 2FA. Applies on save. The Turnstile secret is never sent back to the
 * browser, and changing it needs step-up.
 *
 * @property-read Schema $form
 */
final class SignInSecuritySettings extends Page
{
    /** Saved as typed; the secret is handled separately. */
    private const array KEYS = ['auth.passkeys_enabled', 'auth.turnstile_mode', 'auth.turnstile_site_key', 'auth.lockout_after', 'auth.lockout_minutes', 'auth.remember_days', 'auth.trust_device_days', 'auth.require_2fa_for'];

    protected static ?string $slug = 'settings/sign-in-security';

    protected static ?string $title = 'Sign-in security';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('settings.security');
    }

    public function getSubheading(): string
    {
        return 'Applies to customer sign-in at once. Staff always sign in with a password and an authenticator app.';
    }

    public function mount(): void
    {
        $settings = app(SettingsStore::class);
        $state = ['new_turnstile_secret' => null, 'clear_turnstile_secret' => false];

        foreach (self::KEYS as $key) {
            data_set($state, $key, $settings->get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $secretSet = filled(app(SettingsStore::class)->get('auth.turnstile_secret_key'));

        return $schema->statePath('data')->components([
            Section::make('Passkeys')->schema([
                Toggle::make('auth.passkeys_enabled')->label('Allow sign-in with a passkey')
                    ->helperText('A passkey counts as two factors, so it skips the 2FA screen.'),
            ]),
            Section::make('Bot check (Cloudflare Turnstile)')->columns(2)->schema([
                Select::make('auth.turnstile_mode')->label('When to show it')->required()
                    ->options(['after_failures' => 'After 3 failed sign-ins (account or IP, 15 minutes)', 'always' => 'On every sign-in'])
                    ->columnSpanFull(),
                TextInput::make('auth.turnstile_site_key')->label('Site key')->maxLength(100),
                TextInput::make('new_turnstile_secret')->label('Secret key')->password()->maxLength(100)
                    ->placeholder($secretSet ? '•••• set' : 'Not set')
                    ->helperText($secretSet ? 'Leave empty to keep the current key. Changing it asks for your password.' : 'Without both keys, 3 failed sign-ins pause sign-in instead.'),
                Checkbox::make('clear_turnstile_secret')->label('Remove the secret key')->visible($secretSet),
            ]),
            Section::make('Failed sign-ins')->columns(2)->schema([
                TextInput::make('auth.lockout_after')->label('Pause after this many failures')->numeric()->minValue(3)->maxValue(20)->required()
                    ->helperText('Per account and IP. Without Turnstile keys the pause starts at 3.'),
                TextInput::make('auth.lockout_minutes')->label('Pause for')->numeric()->minValue(1)->maxValue(1440)->suffix('minutes')->required(),
            ]),
            Section::make('Staying signed in')->columns(2)->schema([
                TextInput::make('auth.remember_days')->label('"Keep me signed in" lasts')->numeric()->minValue(1)->maxValue(365)->suffix('days')->required(),
                TextInput::make('auth.trust_device_days')->label('"Don\'t ask again on this device" lasts')->numeric()->minValue(1)->maxValue(365)->suffix('days')->required(),
            ]),
            Section::make('Who must use 2FA')->schema([
                Select::make('auth.require_2fa_for')->hiddenLabel()->required()->options([
                    'tier_owners' => 'Owners of Tier 2 and Tier 3 organizations',
                    'all_owners' => 'All organization owners',
                    'all_staff' => 'All agency staff',
                ])->helperText('Enforced once organization roles and tiers exist (P01). Everyone can turn 2FA on for themselves.'),
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
        return Action::make('save')
            ->label('Save and apply')
            ->schema(fn (): array => $this->needsStepUp() ? array_filter([
                TextInput::make('password')->label('Your password')->password()->required(),
                app(StepUp::class)->needsCode($this->staff()) ? TextInput::make('code')->label('Code from your authenticator app')->required()->length(6) : null,
            ]) : [])
            ->modalHidden(fn (): bool => ! $this->needsStepUp())
            ->modalHeading('Confirm it is you')
            ->modalDescription('Changing the Turnstile secret needs your password and authenticator code. It stays confirmed for '.StepUp::MINUTES.' minutes.')
            ->modalSubmitActionLabel('Confirm and save')
            ->action(function (array $data): void {
                if ($this->needsStepUp()) {
                    $problem = app(StepUp::class)->confirm($this->staff(), is_string($data['password'] ?? null) ? $data['password'] : '', is_string($data['code'] ?? null) ? $data['code'] : null);

                    if ($problem !== null) {
                        Notification::make()->title($problem)->danger()->send();

                        return;
                    }
                }

                $this->save();
            });
    }

    private function needsStepUp(): bool
    {
        $changingSecret = filled(data_get($this->data, 'new_turnstile_secret')) || (bool) data_get($this->data, 'clear_turnstile_secret');

        return $changingSecret && ! app(StepUp::class)->isFresh();
    }

    private function save(): void
    {
        $state = $this->form->getState();
        $settings = app(SettingsStore::class);
        $values = [];
        $errors = [];

        foreach (self::KEYS as $key) {
            $value = data_get($state, $key);
            $value = match ($key) {
                'auth.passkeys_enabled' => (bool) $value,
                'auth.lockout_after', 'auth.lockout_minutes', 'auth.remember_days', 'auth.trust_device_days' => is_numeric($value) ? (int) $value : $value,
                default => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value),
            };
            $values[$key] = $value;
        }

        $secret = data_get($state, 'new_turnstile_secret');

        if (data_get($state, 'clear_turnstile_secret')) {
            $values['auth.turnstile_secret_key'] = null;
        } elseif (is_string($secret) && trim($secret) !== '') {
            $values['auth.turnstile_secret_key'] = trim($secret);
        }

        foreach ($values as $key => $value) {
            try {
                $values[$key] = $settings->validated($key, $value);
            } catch (ValidationException $exception) {
                $field = $key === 'auth.turnstile_secret_key' ? 'new_turnstile_secret' : $key;

                foreach ($exception->errors() as $path => $messages) {
                    $errors['data.'.$field.substr($path, strlen('value'))] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changed = 0;

        DB::transaction(function () use ($values, $settings, &$changed): void {
            foreach ($values as $key => $value) {
                if ($settings->get($key) !== $value) {
                    // Audited by the store; the secret is logged as "[secret]".
                    $settings->set($key, $value);
                    $changed++;
                }
            }
        });

        $this->data['new_turnstile_secret'] = null;
        $this->data['clear_turnstile_secret'] = false;

        Notification::make()->title($changed === 0 ? 'Nothing changed' : 'Saved and applied')->success()->send();
    }

    private function staff(): PlatformStaff
    {
        $staff = Filament::auth()->user();

        if (! $staff instanceof PlatformStaff) {
            abort(403);
        }

        return $staff;
    }
}
