<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Models\PlatformStaff;
use App\Platform\Console\StepUp;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Settings\SettingType;
use App\Theme\BrandAssets;
use App\Theme\ThemeManager;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * A console settings page whose keys are drafted with the theme
 * (PLATFORM_CONSOLE.md § 9.1, § 9.8): the form edits the draft, "Save
 * draft" validates against the settings registry, the preview column shows
 * the draft, and "Publish" makes it live. Every save is audited.
 *
 * @property-read Schema $form
 */
abstract class DraftSettingsPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Changes after each save, so the preview frame reloads. */
    public int $previewVersion = 0;

    /**
     * The registry keys this page edits.
     *
     * @return list<string>
     */
    abstract public static function settingKeys(): array;

    /** The registry permission that opens the page. */
    abstract public static function permission(): string;

    /** @return array<Component> */
    abstract protected function fields(): array;

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows(static::permission());
    }

    public function mount(): void
    {
        $this->form->fill($this->toFormState($this->currentValues()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->fields())->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 5])->schema([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('saveDraft')
                    ->footer([
                        Actions::make([$this->saveDraftAction()])->sticky()->key('form-actions'),
                    ])
                    ->columnSpan(['default' => 1, 'xl' => 3]),
                Group::make([$this->preview()])->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }

    /** The right-hand preview column. */
    protected function preview(): Component
    {
        return View::make('console.settings.preview-frame')->viewData(fn (): array => [
            'url' => route('filament.console.theme-preview', ['surface' => 'landing']),
            'label' => 'Landing page with the draft',
            'version' => $this->previewVersion,
        ]);
    }

    public function saveDraftAction(): Action
    {
        return Action::make('saveDraft')
            ->label('Save draft')
            ->keyBindings(['mod+s'])
            ->schema(fn (): array => $this->needsStepUp() ? $this->stepUpFields() : [])
            // A modal only when the change needs step-up.
            ->modalHidden(fn (): bool => ! $this->needsStepUp())
            ->modalHeading('Confirm it is you')
            ->modalDescription('Changing the canonical origin needs your password (and 2FA code, once enrolled). It stays confirmed for '.StepUp::MINUTES.' minutes.')
            ->modalSubmitActionLabel('Confirm and save')
            ->action(function (array $data): void {
                if ($this->needsStepUp() && ! $this->confirmStepUp($data)) {
                    return;
                }

                $this->saveDraft();
            });
    }

    public function saveDraft(): void
    {
        if ($this->needsStepUp()) {
            Notification::make()->title('Confirm it is you first')->body('Use the Save draft button.')->warning()->send();

            return;
        }

        // Compared after cleaning, so rich text the editor merely reformats
        // does not count as a change.
        $values = $this->validateValues($this->valuesFromForm());
        $current = $this->currentValues();
        $changes = array_filter($values, fn (mixed $value, string $key): bool => ($current[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changes !== []) {
            $this->manager()->saveDraft($changes, $this->staff());
            $this->pruneAssets();
        }

        $this->form->fill($this->toFormState($this->currentValues()));
        $this->previewVersion++;

        Notification::make()
            ->title($changes === [] ? 'Nothing changed' : 'Draft saved')
            ->body($changes === [] ? null : 'Visitors still see the published version until you publish.')
            ->success()
            ->send();

        $this->afterSave($changes);
    }

    /**
     * Warnings to show after a save, e.g. contrast.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function afterSave(array $changes): void {}

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        $manager = $this->manager();

        return [
            Action::make('previewLanding')
                ->label('Open preview')
                ->color('gray')
                ->url(route('filament.console.theme-preview', ['surface' => 'landing']), shouldOpenInNewTab: true),
            Action::make('publish')
                ->label('Publish')
                ->requiresConfirmation()
                ->modalDescription('The draft goes live for every visitor at once. You can restore this version later.')
                ->disabled(fn (): bool => ! $manager->hasDraft())
                ->action(function () use ($manager): void {
                    if (! $manager->hasDraft()) {
                        Notification::make()->title('There is no draft to publish')->warning()->send();

                        return;
                    }

                    $version = $manager->publish($this->staff());
                    $this->pruneAssets();
                    $this->form->fill($this->toFormState($this->currentValues()));
                    $this->previewVersion++;
                    Notification::make()->title("Version {$version} published")->success()->send();
                }),
            Action::make('discard')
                ->label('Discard draft')
                ->color('gray')
                ->requiresConfirmation()
                ->disabled(fn (): bool => ! $manager->hasDraft())
                ->action(function () use ($manager): void {
                    $manager->discardDraft();
                    $this->pruneAssets();
                    $this->form->fill($this->toFormState($this->currentValues()));
                    $this->previewVersion++;
                    Notification::make()->title('Draft discarded')->success()->send();
                }),
            Action::make('reset')
                ->label('Reset to default')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Starts a draft with the shipped default theme, website text and SEO. Nothing changes for visitors until you publish.')
                ->action(function () use ($manager): void {
                    $manager->resetDraftToDefaults($this->staff());
                    $this->form->fill($this->toFormState($this->currentValues()));
                    $this->previewVersion++;
                    Notification::make()->title('Draft reset to the defaults')->success()->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return $this->manager()->hasDraft()
            ? 'You are editing an unpublished draft. Visitors see the published version.'
            : 'No draft: saving starts one. Visitors see the published version until you publish.';
    }

    /**
     * Brand image upload for a role: checked and re-encoded by BrandAssets.
     */
    protected function imageUpload(string $role, string $label, string $help): FileUpload
    {
        $spec = BrandAssets::role($role);
        $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'];

        return FileUpload::make($spec['key'])
            ->label($label)
            ->helperText($help)
            ->disk('brand')
            ->acceptedFileTypes(array_values(array_intersect_key($mimes, array_flip($spec['types']))))
            ->maxSize($spec['max_kb'])
            ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($role): void {
                if ($value instanceof TemporaryUploadedFile && ($problem = app(BrandAssets::class)->problem($role, $value)) !== null) {
                    $fail($problem);
                }
            }])
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(BrandAssets::class)->store($role, $file))
            ->getUploadedFileUsing(fn (string $file): ?array => app(BrandAssets::class)->disk()->exists($file) ? [
                'name' => basename($file),
                'size' => app(BrandAssets::class)->disk()->size($file),
                'type' => app(BrandAssets::class)->mimeType($file),
                'url' => route('brand.asset', ['role' => $role, 'v' => app(BrandAssets::class)->version($file)]),
            ] : null)
            // Old files are pruned once no draft or version names them.
            ->deleteUploadedFileUsing(fn (): null => null);
    }

    /** @return array<string, mixed> */
    protected function currentValues(): array
    {
        $values = $this->manager()->draftValues() ?? $this->manager()->publishedValues();

        return Arr::only($values, static::settingKeys());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function toFormState(array $values): array
    {
        $state = [];

        foreach ($values as $key => $value) {
            data_set($state, $key, $this->toFormValue($key, $value));
        }

        return $state;
    }

    protected function toFormValue(string $key, mixed $value): mixed
    {
        return $value;
    }

    /**
     * The form, read back into registry values: empty text is null, numbers
     * are numbers, lists lose their keys.
     *
     * @return array<string, mixed>
     */
    protected function valuesFromForm(): array
    {
        $state = $this->form->getState();
        $registry = app(SettingsRegistry::class);
        $values = [];

        foreach (static::settingKeys() as $key) {
            $value = $this->fromFormValue($key, data_get($state, $key));
            $type = $registry->get($key)->type;

            $values[$key] = match (true) {
                is_string($value) && trim($value) === '' => null,
                is_string($value) => trim($value),
                $type === SettingType::Integer && is_numeric($value) => (int) $value,
                default => $value,
            };
        }

        return $values;
    }

    protected function fromFormValue(string $key, mixed $value): mixed
    {
        return $value;
    }

    /**
     * Validates every key against the registry and reports all problems at
     * once, on the matching fields. Returns the cleaned values.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function validateValues(array $values): array
    {
        $store = app(SettingsStore::class);
        $errors = [];
        $cleaned = [];

        foreach ($values as $key => $value) {
            try {
                $cleaned[$key] = $store->validated($key, $value);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $errors['data.'.$key.substr($field, strlen('value'))] = $messages;
                }
            } catch (\InvalidArgumentException $exception) {
                $errors['data.'.$key] = [$exception->getMessage()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $cleaned;
    }

    /** Keys whose change needs a fresh step-up (registry `step_up`). */
    protected function needsStepUp(): bool
    {
        $registry = app(SettingsRegistry::class);
        $current = $this->currentValues();

        foreach (static::settingKeys() as $key) {
            if (! $registry->get($key)->stepUp) {
                continue;
            }

            $value = $this->fromFormValue($key, data_get($this->data ?? [], $key));
            $value = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);

            if (($current[$key] ?? null) !== $value) {
                return ! app(StepUp::class)->isFresh();
            }
        }

        return false;
    }

    /** @return array<Component> */
    protected function stepUpFields(): array
    {
        $fields = [TextInput::make('password')->label('Your password')->password()->required()];

        if (app(StepUp::class)->needsCode($this->staff())) {
            $fields[] = TextInput::make('code')->label('Code from your authenticator app')->required()->length(6);
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $data */
    protected function confirmStepUp(array $data): bool
    {
        $problem = app(StepUp::class)->confirm(
            $this->staff(),
            is_string($data['password'] ?? null) ? $data['password'] : '',
            is_string($data['code'] ?? null) ? $data['code'] : null,
        );

        if ($problem !== null) {
            Notification::make()->title($problem)->danger()->send();

            return false;
        }

        return true;
    }

    protected function manager(): ThemeManager
    {
        return app(ThemeManager::class);
    }

    protected function staff(): PlatformStaff
    {
        $staff = Filament::auth()->user();

        if (! $staff instanceof PlatformStaff) {
            abort(403);
        }

        return $staff;
    }

    protected function pruneAssets(): void
    {
        app(BrandAssets::class)->prune($this->manager()->valueSets());
    }
}
