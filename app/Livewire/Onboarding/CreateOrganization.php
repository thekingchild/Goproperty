<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OrganizationSignup;
use App\Onboarding\OrganizationSlug;
use App\Rules\NigerianMobile;
use App\Support\NigerianStates;
use App\Support\PhoneNumber;
use App\Theme\ThemeContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Signup, organization step (ONBOARDING_COMPLIANCE.md § 2 step 2) at
 * /signup/organization: type, name, web address (suggested from the name),
 * state and LGA, optional CAC number. Saving creates the organization with
 * the user as its `org-owner` and continues in the new workspace's setup
 * (SetupWizard). A user already signing up an organization is sent there.
 *
 * The account's mobile number is asked again only when signup could not
 * keep it (it belonged to another account, RegisterAccount).
 */
#[Layout('layouts::onboarding')]
#[Title('Your organization')]
final class CreateOrganization extends Component
{
    public OrganizationForm $form;

    public string $slug = '';

    /** The address last suggested from the name; one the user typed is never replaced. */
    public string $suggestedSlug = '';

    public string $phone = '';

    public function mount(OrganizationSignup $signup): void
    {
        $this->authorize('create', Organization::class);

        $pending = $signup->inProgressFor($this->user());

        if ($pending !== null) {
            $this->redirectRoute('workspace.setup', $pending);
        }
    }

    /** Suggests the address from the name until the user types their own. */
    public function updatedFormName(): void
    {
        if ($this->slug !== '' && $this->slug !== $this->suggestedSlug) {
            return;
        }

        $this->suggestedSlug = trim($this->form->name) === '' ? '' : app(OrganizationSlug::class)->suggest($this->form->name);
        $this->slug = $this->suggestedSlug;
    }

    public function updatedSlug(): void
    {
        $this->slug = Str::lower(trim($this->slug));
    }

    public function save(OrganizationSignup $signup, OrganizationSlug $slugs): void
    {
        $this->authorize('create', Organization::class);
        $user = $this->user();

        // Each try reveals whether an address is taken; keep that slow.
        if (RateLimiter::tooManyAttempts('signup-organization:'.$user->id, 10)) {
            throw ValidationException::withMessages(['slug' => 'Too many tries. Please wait a minute and try again.']);
        }

        RateLimiter::hit('signup-organization:'.$user->id, 60);

        $details = $this->form->details();
        $this->slug = Str::lower(trim($this->slug));

        $this->validate([
            'slug' => ['required', 'string', 'min:'.OrganizationSlug::MIN, 'max:'.OrganizationSlug::MAX, 'regex:'.OrganizationSlug::PATTERN],
        ], ['slug.regex' => 'Use lower-case letters, numbers and single hyphens.'], ['slug' => 'web address']);

        if (! $slugs->isAvailable($this->slug)) {
            throw ValidationException::withMessages(['slug' => 'That address is taken. Try another.']);
        }

        $phone = null;

        if ($user->phone === null) {
            $this->validate(['phone' => ['required', 'string', 'max:32', new NigerianMobile]], [], ['phone' => 'mobile number']);
            $phone = PhoneNumber::nigerianMobile($this->phone);

            if (User::query()->where('phone', $phone)->exists()) {
                throw ValidationException::withMessages(['phone' => 'This number is used by another account. Enter a different one.']);
            }
        }

        try {
            $organization = $signup->start($user, $details, $this->slug, $phone);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That address was just taken. Try another.']);
        }

        $this->redirectRoute('workspace.setup', $organization);
    }

    public function render(): View
    {
        return view('livewire.onboarding.create-organization', [
            'step' => OnboardingStep::Organization,
            'types' => OrganizationType::cases(),
            'states' => NigerianStates::ALL,
            'needsPhone' => $this->user()->phone === null,
            'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'goproperty.ng',
            'brand' => app(ThemeContext::class)->current()->name(),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
