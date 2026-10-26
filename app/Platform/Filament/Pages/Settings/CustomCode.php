<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Models\PlatformStaff;
use App\Platform\Console\StepUp;
use App\Settings\SettingsStore;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
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
 * Settings → Custom code (PLATFORM_CONSOLE.md § 9.5): analytics, chat and
 * verification snippets for the public marketing pages only. Applies on
 * save (no draft), needs step-up, and each change is audited in full.
 *
 * @property-read Schema $form
 */
final class CustomCode extends Page
{
    public const array KEYS = ['custom_code.head', 'custom_code.body_start', 'custom_code.body_end', 'custom_code.css', 'custom_code.allowed_hosts'];

    protected static ?string $slug = 'settings/custom-code';

    protected static ?string $title = 'Custom code';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('settings.custom_code');
    }

    public function getSubheading(): string
    {
        return 'Printed on public marketing pages only: never on sign-in, app, pay or verify pages, the console, or for signed-in users. Add ?safe=1 to any public page to load it without this code.';
    }

    public function mount(): void
    {
        $settings = app(SettingsStore::class);
        $state = [];

        foreach (self::KEYS as $key) {
            data_set($state, $key, $settings->get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $code = fn (string $key, string $label, string $help): Textarea => Textarea::make($key)->label($label)->helperText($help)
            ->rows(6)->maxLength(51200)->extraInputAttributes(['spellcheck' => 'false', 'style' => 'font-family:ui-monospace,monospace;font-size:.8125rem']);

        return $schema->statePath('data')->components([
            Section::make('Scripts and styles')->schema([
                $code('custom_code.head', 'End of <head>', 'Analytics tags and site verification meta tags.'),
                $code('custom_code.body_start', 'Right after <body>', 'For example a tag manager <noscript> frame.'),
                $code('custom_code.body_end', 'Before </body>', 'Chat widgets and other late scripts.'),
                $code('custom_code.css', 'Custom CSS', 'Printed in a <style> block; it must not contain </style.'),
            ]),
            Section::make('Allowed script hosts')
                ->description('The public pages\' security policy blocks scripts from any other host. Add the host a snippet loads from, e.g. connect.facebook.net.')
                ->schema([
                    TagsInput::make('custom_code.allowed_hosts')->hiddenLabel()->placeholder('www.googletagmanager.com'),
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
            ->schema(fn (): array => app(StepUp::class)->isFresh() ? [] : array_filter([
                TextInput::make('password')->label('Your password')->password()->required(),
                app(StepUp::class)->needsCode($this->staff()) ? TextInput::make('code')->label('Code from your authenticator app')->required()->length(6) : null,
            ]))
            ->modalHidden(fn (): bool => app(StepUp::class)->isFresh())
            ->modalHeading('Confirm it is you')
            ->modalDescription('Custom code runs on every public page. Confirm with your password (and 2FA code, once enrolled); it stays confirmed for '.StepUp::MINUTES.' minutes.')
            ->modalSubmitActionLabel('Confirm and save')
            ->action(function (array $data): void {
                $stepUp = app(StepUp::class);

                if (! $stepUp->isFresh()) {
                    $problem = $stepUp->confirm($this->staff(), is_string($data['password'] ?? null) ? $data['password'] : '', is_string($data['code'] ?? null) ? $data['code'] : null);

                    if ($problem !== null) {
                        Notification::make()->title($problem)->danger()->send();

                        return;
                    }
                }

                $this->save();
            });
    }

    private function save(): void
    {
        $state = $this->form->getState();
        $settings = app(SettingsStore::class);
        $values = [];
        $errors = [];

        foreach (self::KEYS as $key) {
            $value = data_get($state, $key);
            $value = match (true) {
                $key === 'custom_code.allowed_hosts' => array_values(array_unique(array_map(fn (mixed $host): string => strtolower(trim((string) $host)), is_array($value) ? $value : []))),
                is_string($value) && trim($value) === '' => null,
                default => $value,
            };

            try {
                $values[$key] = $settings->validated($key, $value);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $errors['data.'.$key.substr($field, strlen('value'))] = $messages;
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
                    // Audited by the store with the full before and after.
                    $settings->set($key, $value);
                    $changed++;
                }
            }
        });

        Notification::make()->title($changed === 0 ? 'Nothing changed' : 'Saved and live on the public pages')->success()->send();
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
