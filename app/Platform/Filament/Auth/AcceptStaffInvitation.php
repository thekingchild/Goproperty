<?php

declare(strict_types=1);

namespace App\Platform\Filament\Auth;

use App\Platform\Staff\StaffChangeRefused;
use App\Platform\Staff\StaffLifecycle;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * First sign-in of an invited staff member (PLATFORM_CONSOLE.md § 1):
 * the invitation link opens this page, the invitee sets a password and is
 * signed in, and every console page then sends them to set up 2FA. The
 * account becomes active once 2FA is enrolled (StaffAccountState).
 *
 * @property-read Schema $form
 */
final class AcceptStaffInvitation extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $valid = false;

    #[Locked]
    public string $email = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(#[SensitiveParameter] string $token): void
    {
        $this->token = $token;
        $staff = app(StaffLifecycle::class)->findInvitation($token);
        $this->valid = $staff !== null;
        $this->email = $staff->email ?? '';

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('password')
                ->label('Choose a password')
                ->helperText('At least 12 characters.')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required()
                ->rule(StaffLifecycle::passwordRule())
                ->same('passwordConfirmation'),
            TextInput::make('passwordConfirmation')
                ->label('Type it again')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required()
                ->dehydrated(false),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->valid) {
            return $schema->components([
                Text::make('This invitation link is not valid any more: it was used, it expired, or it was replaced by a newer one. Ask a GoProperty administrator to send a new invitation.'),
            ]);
        }

        return $schema->components([
            Text::make("You are setting up the staff account for {$this->email}. Next you will set up an authenticator app: two-factor authentication is required."),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('accept')
                ->footer([
                    Actions::make([
                        Action::make('accept')->label('Set password and continue')->submit('accept'),
                    ])->fullWidth()->key('form-actions'),
                ]),
        ]);
    }

    public function accept(): ?RedirectResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()->title("Too many attempts. Try again in {$exception->secondsUntilAvailable} seconds.")->danger()->send();

            return null;
        }

        if (! $this->valid) {
            return null;
        }

        $data = $this->form->getState();
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';

        try {
            $staff = app(StaffLifecycle::class)->acceptInvitation($this->token, $password);
        } catch (StaffChangeRefused $exception) {
            $this->valid = false;
            Notification::make()->title($exception->getMessage())->danger()->send();

            return null;
        }

        Filament::auth()->login($staff);
        session()->regenerate();

        return redirect()->to(Filament::getSetUpRequiredMultiFactorAuthenticationUrl() ?? Filament::getUrl());
    }

    public function getTitle(): string
    {
        return 'Accept invitation';
    }

    public function getHeading(): string
    {
        return 'Join the staff console';
    }
}
