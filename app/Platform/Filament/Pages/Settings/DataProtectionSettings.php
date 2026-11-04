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
 * Settings → Data protection (P09-02; NDPA 2023 s. 32: a data controller of
 * major importance names a data protection officer): the officer's name,
 * email, phone and postal address, shown on the public privacy page
 * (/privacy) for people to write to. `privacy.settings` (compliance-officer,
 * super-admin); every change audited by the settings store; applies on save.
 *
 * @property-read Schema $form
 */
final class DataProtectionSettings extends Page
{
    /** @var array<string, string> form field => setting key */
    private const array KEYS = [
        'name' => 'privacy.dpo_name',
        'email' => 'privacy.dpo_email',
        'phone' => 'privacy.dpo_phone',
        'address' => 'privacy.dpo_address',
    ];

    protected static ?string $slug = 'settings/data-protection';

    protected static ?string $title = 'Data protection officer';

    protected static ?string $navigationLabel = 'Data protection';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 12;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('privacy.settings');
    }

    public function getSubheading(): string
    {
        return 'Shown on the privacy page, for anyone who wants a copy of their data, a correction or an erasure, or to raise a concern. Use an address the officer reads every working day.';
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
            Section::make('Data protection officer')->columns(2)->schema([
                TextInput::make('name')->label('Name')->maxLength(120),
                TextInput::make('email')->label('Email')->email()->maxLength(254),
                TextInput::make('phone')->label('Phone')->tel()->maxLength(20),
                TextInput::make('address')->label('Postal address')->maxLength(255),
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
                    'email' => 'Give an email address.',
                    'phone' => 'Give a phone number, such as +234 803 123 4567.',
                    default => 'Use plain text, without < or >.',
                }]);
            }
        }

        if (($values['privacy.dpo_name'] === null) !== ($values['privacy.dpo_email'] === null)) {
            throw ValidationException::withMessages(['data.email' => 'Give both the officer\'s name and email, or neither.']);
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

        Notification::make()->title($changed === 0 ? 'Nothing changed' : 'Saved: the privacy page shows these details')->success()->send();
    }
}
