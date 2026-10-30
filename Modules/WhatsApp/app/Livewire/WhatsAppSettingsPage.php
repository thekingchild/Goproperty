<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\WhatsApp\Connections\ConnectionRefused;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Connections\WhatsAppPlan;
use Modules\WhatsApp\Meta\CheckResult;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Models\WhatsAppTemplate;
use Modules\WhatsApp\Templates\TemplateSync;

/**
 * Settings → WhatsApp, /o/{organization}/settings/operations/whatsapp
 * (ARCHITECTURE.md § 6.7): the organization's own WhatsApp Business
 * account. Connect with the WhatsApp Business Account ID, the phone number
 * ID, a permanent access token and the app secret (tried with Meta first),
 * see the connection (token masked; the app secret never shown), check it
 * again, copy the webhook address and verify token for Meta's app
 * settings, disconnect; sync the templates from Meta and see each one's
 * status, category and variables. The messages' templates are on
 * WhatsAppMessagesPage.
 *
 * Owners and administrators open it (`settings.view`); only org-owners see
 * the verify token and change the credentials (`whatsapp.connection`,
 * never while GoProperty staff impersonate); syncing needs
 * `whatsapp.messages`. Read-only while the organization is suspended. The
 * token and secret typed are cleared from the component after every
 * attempt, so they never go back to the browser.
 */
#[Layout('layouts::app')]
#[Title('WhatsApp')]
final class WhatsAppSettingsPage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $wabaId = '';

    public string $phoneNumberId = '';

    public string $accessToken = '';

    public string $appSecret = '';

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, WhatsAppConnections $connections): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', WhatsAppConnection::class);
        $connection = $connections->current();

        if ($connection?->connected()) {
            $this->wabaId = (string) $connection->waba_id;
            $this->phoneNumberId = (string) $connection->phone_number_id;
        }
    }

    #[BlockedWhileImpersonating]
    public function connect(WhatsAppConnections $connections): void
    {
        $this->authorize('connect', WhatsAppConnection::class);
        $this->resetErrorBag();
        $token = $this->accessToken;
        $secret = $this->appSecret;
        // Never kept in the component: they do not go back to the browser.
        $this->accessToken = '';
        $this->appSecret = '';

        try {
            $connection = $connections->connect($this->user(), $this->wabaId, $this->phoneNumberId, $token, $secret);
        } catch (ConnectionRefused $refused) {
            $this->status = null;
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        $this->status = $connection->active()
            ? __('Meta accepted the connection. Now set up the webhook below in your Meta app, then sync your templates.')
            : __('Saved, but Meta could not be reached to check it. Check the connection again in a few minutes; nothing goes by WhatsApp until the check passes.');
    }

    #[BlockedWhileImpersonating]
    public function test(WhatsAppConnections $connections): void
    {
        $this->authorize('connect', WhatsAppConnection::class);
        $this->attempt(fn (): string => match ($connections->test($this->user())) {
            CheckResult::Accepted => __('Meta accepted the connection.'),
            CheckResult::Refused => __('Meta refused the access token. Nothing goes by WhatsApp until you save a working one; reminders use SMS meanwhile.'),
            CheckResult::Unavailable => __('Meta could not be reached. Try again in a few minutes.'),
        });
    }

    #[BlockedWhileImpersonating]
    public function newVerifyToken(WhatsAppConnections $connections): void
    {
        $this->authorize('connect', WhatsAppConnection::class);
        $this->attempt(function () use ($connections): string {
            $connections->newVerifyToken($this->user());

            return __('A new verify token was made. Paste it into your Meta app when you set up the webhook again.');
        });
    }

    #[BlockedWhileImpersonating]
    public function disconnect(WhatsAppConnections $connections): void
    {
        $this->authorize('connect', WhatsAppConnection::class);
        $this->attempt(function () use ($connections): string {
            $connections->disconnect($this->user());
            $this->wabaId = '';
            $this->phoneNumberId = '';

            return __('WhatsApp disconnected and your access token and app secret removed. Reminders use SMS again.');
        });
    }

    public function sync(WhatsAppConnections $connections, TemplateSync $sync): void
    {
        $this->authorize('manageMessages', WhatsAppConnection::class);
        $this->resetErrorBag();
        $connection = $connections->current();

        if (! $connection instanceof WhatsAppConnection || ! $connection->connected()) {
            $this->addError('connection', __('Connect your WhatsApp Business account first.'));

            return;
        }

        $result = $sync->sync($connection, $this->user());

        if ($result->error !== null) {
            $this->status = null;
            $this->addError('connection', __('The templates could not be fetched from Meta: :error', ['error' => $result->error]));

            return;
        }

        $this->status = match (true) {
            ! $result->fetched => __('Templates are fetched from Meta only on the live site; the ones saved are unchanged.'),
            $result->disabledEvents !== [] => trans_choice('{1} :count template fetched. WhatsApp was switched off for :events: its template can no longer be sent, so it goes by its next channel.|[2,*] :count templates fetched. WhatsApp was switched off for :events: their template can no longer be sent, so they go by their next channel.', $result->templates, ['count' => $result->templates, 'events' => implode(', ', $result->disabledEvents)]),
            default => trans_choice('{0} No templates found in your WhatsApp Business account.|{1} :count template fetched.|[2,*] :count templates fetched.', $result->templates, ['count' => $result->templates]),
        };
    }

    public function render(WhatsAppConnections $connections, WhatsAppPlan $plan, OrganizationContext $context): View
    {
        $connection = $connections->current();
        $connected = $connection?->connected() === true ? $connection : null;
        $user = $this->user();

        return view('whatsapp::livewire.whatsapp-settings-page', [
            'connection' => $connected,
            'planAllows' => $plan->allows($context->get()),
            'webhookUrl' => $connected !== null ? route('whatsapp.webhook', ['ulid' => $connected->webhook_ulid]) : null,
            'templates' => $connected !== null ? WhatsAppTemplate::query()->orderBy('name')->orderBy('language')->get() : collect(),
            'canConnect' => $user->can('connect', WhatsAppConnection::class),
            'canSync' => $user->can('manageMessages', WhatsAppConnection::class),
            'brand' => app(ThemeContext::class)->current()->name(),
        ]);
    }

    /** @param  callable(): string  $change */
    private function attempt(callable $change): void
    {
        $this->resetErrorBag();

        try {
            $this->status = $change();
        } catch (ConnectionRefused $refused) {
            $this->status = null;
            $this->addError('connection', $refused->getMessage());
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
