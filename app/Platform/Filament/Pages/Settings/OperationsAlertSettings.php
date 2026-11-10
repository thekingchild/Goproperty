<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Platform\Console\StepUp;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\Notices\AlertNotice;
use App\Platform\Operations\Alerts\Notices\AlertNotifier;
use App\Platform\Operations\Alerts\Notices\NoticeKind;
use App\Platform\Operations\Alerts\Notices\SlackWebhook;
use App\Platform\Operations\OperationsAudit;
use App\Platform\Staff\StaffPermission;
use App\Settings\SettingsStore;
use BackedEnum;
use Carbon\CarbonImmutable;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Settings → Operations alerts (PLATFORM_CONSOLE.md § 1A, § 2 "Alerts";
 * P08-03): whether alerts are sent, the Slack incoming webhook (a secret:
 * stored encrypted, never shown back, only its last four characters;
 * changing it asks for step-up), which staff are emailed, the thresholds,
 * how often a lasting alert is repeated, and the uptime link for an outside
 * monitor (shown once when made). `operations.alerts` (technical-admin,
 * super-admin). Every change is audited by the settings store; a test alert
 * is audited here.
 *
 * @property-read Schema $form
 */
final class OperationsAlertSettings extends Page
{
    /** Saved as typed; the Slack URL is handled apart. */
    private const array KEYS = [
        'operations.alerts_enabled', 'operations.alert_staff_ids', 'operations.alert_scheduler_minutes', 'operations.alert_queue_wait_minutes',
        'operations.alert_webhook_failure_percent', 'operations.alert_error_spike', 'operations.alert_renotify_minutes',
    ];

    protected static ?string $slug = 'settings/operations-alerts';

    protected static ?string $title = 'Operations alerts';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows(StaffPermission::OperationsAlerts->value);
    }

    public function getSubheading(): string
    {
        return 'Alerts go to Slack and by email when something on Operations → System operations fails: when it starts, again while it lasts, and once when it clears.';
    }

    public function mount(): void
    {
        $settings = app(SettingsStore::class);
        $state = ['new_slack_url' => null, 'clear_slack_url' => false];

        foreach (self::KEYS as $key) {
            data_set($state, $key, $settings->get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $settings = AlertSettings::current();

        return $schema->statePath('data')->components([
            Section::make('Sending')->schema([
                Toggle::make('operations.alerts_enabled')->label('Send operations alerts')
                    ->helperText('Off: problems still show on the dashboard, nothing is sent.'),
            ]),
            Section::make('Slack')->schema([
                TextInput::make('new_slack_url')->label('Incoming webhook URL')->password()->maxLength(300)
                    ->placeholder($settings->maskedSlackUrl() ?? 'Not set')
                    ->helperText(($settings->slackWebhookUrl !== null ? 'Leave empty to keep the current one. ' : '').'From Slack: Apps → Incoming Webhooks; it starts with https://hooks.slack.com/. Changing it asks for your password.'),
                Checkbox::make('clear_slack_url')->label('Remove the Slack webhook')->visible($settings->slackWebhookUrl !== null),
            ]),
            Section::make('Email')->schema([
                Select::make('operations.alert_staff_ids')->label('Staff to email')->multiple()->searchable()
                    ->options(fn (): array => PlatformStaff::query()->where('status', StaffStatus::Active)->orderBy('name')->get()
                        ->mapWithKeys(fn (PlatformStaff $staff): array => [$staff->id => "{$staff->name} ({$staff->email})"])->all())
                    ->helperText('Empty: every active staff member with "System status and heartbeats" (technical-admin, super-admin). Only staff who may see the operations dashboard are emailed.'),
            ]),
            Section::make('When alerts fire')->columns(2)->schema([
                TextInput::make('operations.alert_scheduler_minutes')->label('Scheduler stopped after')->numeric()->minValue(3)->maxValue(60)->suffix('minutes')->required()
                    ->helperText('Without a heartbeat for this long. Cron runs it every minute.'),
                TextInput::make('operations.alert_queue_wait_minutes')->label('A queue waits longer than')->numeric()->minValue(1)->maxValue(240)->suffix('minutes')->required(),
                TextInput::make('operations.alert_webhook_failure_percent')->label('Paystack webhook failures above')->numeric()->minValue(1)->maxValue(100)->suffix('%')->required()
                    ->helperText('Over the last hour, from 3 deliveries; the platform\'s own account and each agency\'s.'),
                TextInput::make('operations.alert_error_spike')->label('Errors in 15 minutes, from')->numeric()->minValue(1)->maxValue(100000)->required(),
                TextInput::make('operations.alert_renotify_minutes')->label('Repeat a lasting alert every')->numeric()->minValue(15)->maxValue(1440)->suffix('minutes')->required()
                    ->helperText('The quarterly drill reminder is sent once.'),
            ]),
            Section::make('Uptime link')->schema([
                TextInput::make('uptime_link_state')->hiddenLabel()->disabled()->dehydrated(false)
                    ->placeholder($settings->uptimeTokenHash !== null ? 'Made. It is shown only when made: make a new one if it is lost.' : 'Not made yet.')
                    ->helperText('For an outside uptime monitor (checked every 5 minutes, say): it answers 200 while the scheduler runs and 503 once it has stopped, and each check also lets this site raise its own alert when nobody visits it.'),
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

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send a test alert')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('A test message goes to Slack and to the staff emailed, with the settings saved now.')
                ->action(fn () => $this->sendTest()),
            $this->stepUpAction('makeUptimeLink', 'Make a new uptime link', fn () => $this->makeUptimeLink()),
            $this->stepUpAction('removeUptimeLink', 'Remove the uptime link', fn () => $this->removeUptimeLink())
                ->color('danger')
                ->visible(fn (): bool => AlertSettings::current()->uptimeTokenHash !== null),
        ];
    }

    public function saveAction(): Action
    {
        return $this->stepUpAction('save', 'Save', fn () => $this->save(), when: fn (): bool => filled(data_get($this->data, 'new_slack_url')) || (bool) data_get($this->data, 'clear_slack_url'))
            ->color('primary');
    }

    /** @param  (callable(): bool)|null  $when  step-up needed only when this says so */
    private function stepUpAction(string $name, string $label, callable $run, ?callable $when = null): Action
    {
        $needs = fn (): bool => ($when === null || $when()) && ! app(StepUp::class)->isFresh();

        return Action::make($name)
            ->label($label)
            ->color('gray')
            ->schema(fn (): array => $needs() ? array_filter([
                TextInput::make('password')->label('Your password')->password()->required(),
                app(StepUp::class)->needsCode($this->staff()) ? TextInput::make('code')->label('Code from your authenticator app')->required()->length(6) : null,
            ]) : [])
            ->modalHidden(fn (): bool => ! $needs() && $name === 'save')
            ->modalHeading(fn (): string => $needs() ? 'Confirm it is you' : $label)
            ->modalDescription(fn (): ?string => $needs() ? 'The Slack webhook and the uptime link are secrets: changing them needs your password and authenticator code. It stays confirmed for '.StepUp::MINUTES.' minutes.' : null)
            ->modalSubmitActionLabel('Confirm')
            ->action(function (array $data) use ($needs, $run): void {
                abort_unless(self::canAccess(), 403);

                if ($needs()) {
                    $problem = app(StepUp::class)->confirm($this->staff(), is_string($data['password'] ?? null) ? $data['password'] : '', is_string($data['code'] ?? null) ? $data['code'] : null);

                    if ($problem !== null) {
                        Notification::make()->title($problem)->danger()->send();

                        return;
                    }
                }

                $run();
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
            $values[$key] = match (true) {
                $key === 'operations.alerts_enabled' => (bool) $value,
                $key === 'operations.alert_staff_ids' => array_values(array_map(intval(...), is_array($value) ? $value : [])),
                // The thresholds: whole numbers.
                default => is_numeric($value) ? (int) $value : $value,
            };
        }

        $slack = data_get($state, 'new_slack_url');

        if (data_get($state, 'clear_slack_url')) {
            $values['operations.alert_slack_webhook_url'] = null;
        } elseif (is_string($slack) && trim($slack) !== '') {
            $values['operations.alert_slack_webhook_url'] = trim($slack);
        }

        foreach ($values as $key => $value) {
            try {
                $values[$key] = $settings->validated($key, $value);
            } catch (ValidationException $exception) {
                $field = $key === 'operations.alert_slack_webhook_url' ? 'new_slack_url' : $key;

                foreach ($exception->errors() as $path => $messages) {
                    $errors['data.'.$field.substr($path, strlen('value'))] = $key === 'operations.alert_slack_webhook_url'
                        ? ['Give a Slack incoming webhook URL: https://hooks.slack.com/services/…']
                        : $messages;
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
                    // Audited by the store; the Slack URL as "[secret]".
                    $settings->set($key, $value);
                    $changed++;
                }
            }
        });

        $this->data['new_slack_url'] = null;
        $this->data['clear_slack_url'] = false;

        Notification::make()->title($changed === 0 ? 'Nothing changed' : 'Saved')->success()->send();
    }

    private function sendTest(): void
    {
        abort_unless(self::canAccess(), 403);
        $staff = $this->staff();

        if (RateLimiter::tooManyAttempts('operations-alert-test:'.$staff->id, 5)) {
            Notification::make()->title('Too many test alerts. Try again in a minute.')->warning()->send();

            return;
        }

        RateLimiter::hit('operations-alert-test:'.$staff->id);
        $settings = AlertSettings::current();
        $result = app(AlertNotifier::class)->send(new AlertNotice(NoticeKind::Test, null, "{$staff->name} sent a test of the operations alerts.", null, CarbonImmutable::now(), AlertNotifier::dashboardUrl()), $settings);
        // Slack is called only in production (SlackWebhook): elsewhere the
        // message goes to the log, and the page must not say Slack took it.
        $called = $result['slack'] !== null && app(SlackWebhook::class)->live();
        app(OperationsAudit::class)->record('alert.test_sent', 'Test operations alert sent', causer: $staff, properties: ['slack' => $called ? $result['slack'] : null, 'slack_logged' => $result['slack'] !== null && ! $called, 'emails' => $result['emails']]);

        $slack = match (true) {
            $result['slack'] === null => 'Slack is not set',
            ! $called => 'Slack is called only in production, so the Slack message went to the log',
            $result['slack'] => 'Slack took it',
            default => 'Slack refused it (see the log)',
        };

        Notification::make()->title("Test sent. {$slack}; {$result['emails']} staff emailed.")->success()->send();
    }

    private function makeUptimeLink(): void
    {
        $token = Str::random(40);
        // Audited by the store as "[secret]"; only the hash is kept.
        app(SettingsStore::class)->set('operations.uptime_token_hash', hash('sha256', $token));

        Notification::make()
            ->title('Uptime link made')
            ->body('Copy it into your uptime monitor now; it is not shown again: '.route('operations.uptime', ['token' => $token]))
            ->persistent()
            ->success()
            ->send();
    }

    private function removeUptimeLink(): void
    {
        app(SettingsStore::class)->forget('operations.uptime_token_hash');

        Notification::make()->title('Uptime link removed: it now answers 404')->success()->send();
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
