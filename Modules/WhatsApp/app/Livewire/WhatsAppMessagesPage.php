<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Livewire;

use App\Models\User;
use App\Notifications\Channels\WhatsAppEvent;
use App\Notifications\Channels\WhatsAppEvents;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\WhatsApp\Mappings\ChannelMappings;
use Modules\WhatsApp\Mappings\MappingRefused;
use Modules\WhatsApp\Models\ChannelMapping;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Models\WhatsAppTemplate;

/**
 * Settings → WhatsApp → Messages and templates,
 * /o/{organization}/settings/operations/whatsapp/messages
 * (ARCHITECTURE.md § 6.7): every message GoProperty may send by WhatsApp
 * (WhatsAppEvents), with the template it uses, whether it is on, and its
 * channel order. Editing one: choose an approved utility template, what
 * fills each of its variables (the message's own values only), the order
 * of WhatsApp and SMS (reminders on an SMS rung; everything else is
 * WhatsApp only), switch it on, see a preview with sample values; a
 * reminder's choice can be copied to the ladder's other rungs.
 *
 * Owners and administrators open it (`settings.view`) and change it
 * (`whatsapp.messages`, through ChannelMappings). Read-only while the
 * organization is suspended.
 */
#[Layout('layouts::app')]
#[Title('WhatsApp messages')]
final class WhatsAppMessagesPage extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?string $editing = null;

    public ?int $templateId = null;

    /** @var list<string> the placeholder for each of the template's variables, by position */
    public array $placeholders = [];

    public string $channelOrder = 'whatsapp';

    public bool $active = false;

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

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', WhatsAppConnection::class);
    }

    public function edit(string $event, ChannelMappings $mappings, WhatsAppEvents $events): void
    {
        $this->authorize('manageMessages', WhatsAppConnection::class);
        $definition = $events->find($event);
        abort_if($definition === null, 404);

        $this->resetErrorBag();
        $this->status = null;
        $this->editing = $definition->key;
        $mapping = $mappings->for($definition->key);
        $this->templateId = $mapping?->whatsapp_template_id;
        $this->active = $mapping->active ?? false;
        $this->channelOrder = implode(',', $mapping->channel_order ?? $definition->channels());
        $this->placeholders = [];

        $template = $this->template();

        foreach ($template?->layout()->variables() ?? [] as $variable) {
            $this->placeholders[] = (string) ($mapping?->variable_map[$variable] ?? '');
        }
    }

    public function updatedTemplateId(): void
    {
        $this->placeholders = array_fill(0, count($this->template()?->layout()->variables() ?? []), '');
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(ChannelMappings $mappings): void
    {
        $this->authorize('manageMessages', WhatsAppConnection::class);
        $this->resetErrorBag();

        if ($this->editing === null) {
            return;
        }

        try {
            $mappings->save($this->user(), $this->editing, $this->templateId, $this->variableMap(), $this->order(), $this->active);
        } catch (MappingRefused $refused) {
            $this->addError(str_starts_with($refused->field, 'variableMap.') ? 'placeholders' : $refused->field, $refused->getMessage());

            return;
        }

        $this->status = __('Saved.');
        $this->editing = null;
    }

    public function copyToRungs(ChannelMappings $mappings): void
    {
        $this->authorize('manageMessages', WhatsAppConnection::class);
        $this->resetErrorBag();

        if ($this->editing === null) {
            return;
        }

        try {
            $mappings->save($this->user(), $this->editing, $this->templateId, $this->variableMap(), $this->order(), $this->active);
            $saved = $mappings->copyToSiblings($this->user(), $this->editing);
        } catch (MappingRefused $refused) {
            $this->addError(str_starts_with($refused->field, 'variableMap.') ? 'placeholders' : $refused->field, $refused->getMessage());

            return;
        }

        $this->status = trans_choice('{0} Saved.|{1} Saved, and copied to :count other day of this reminder.|[2,*] Saved, and copied to the :count other days of this reminder.', count($saved), ['count' => count($saved)]);
        $this->editing = null;
    }

    public function render(WhatsAppEvents $events, ChannelMappings $mappings): View
    {
        $user = $this->user();
        $mapped = ChannelMapping::query()->with('template')->get()->keyBy('event_key');
        $definition = $this->editing !== null ? $events->find($this->editing) : null;
        $template = $this->template();
        $groups = [];

        foreach ($events->all() as $event) {
            $groups[$event->group][] = ['event' => $event, 'mapping' => $mapped->get($event->key)];
        }

        return view('whatsapp::livewire.whatsapp-messages-page', [
            'groups' => $groups,
            'definition' => $definition,
            'templates' => WhatsAppTemplate::query()->orderBy('name')->orderBy('language')->get()->filter(fn (WhatsAppTemplate $template): bool => $template->usable() || $template->id === $this->templateId),
            'template' => $template,
            'variables' => $template?->layout()->variables() ?? [],
            'orders' => $definition !== null ? self::orders($definition) : [],
            'preview' => $definition !== null && $template !== null ? $mappings->preview($definition, $template, $this->variableMap()) : null,
            'hasSiblings' => $this->editing !== null && $mappings->siblings($this->editing) !== [],
            'canManage' => $user->can('manageMessages', WhatsAppConnection::class),
        ]);
    }

    /** @return array<string, string> "whatsapp,sms" => "WhatsApp, then SMS" */
    public static function orders(WhatsAppEvent $event): array
    {
        if ($event->channels() === ['whatsapp']) {
            return ['whatsapp' => __('WhatsApp only')];
        }

        return [
            'whatsapp,sms' => __('WhatsApp, then SMS if WhatsApp cannot be used'),
            'whatsapp' => __('WhatsApp only (no SMS)'),
            'sms,whatsapp' => __('SMS, then WhatsApp if SMS cannot be used'),
        ];
    }

    /**
     * @param  list<string>  $order
     * @return list<string>
     */
    public static function describeOrder(array $order): array
    {
        return array_map(static fn (string $channel): string => $channel === 'sms' ? 'SMS' : 'WhatsApp', $order);
    }

    private function template(): ?WhatsAppTemplate
    {
        return $this->templateId !== null ? WhatsAppTemplate::query()->find($this->templateId) : null;
    }

    /** @return array<string, string> variable => placeholder */
    private function variableMap(): array
    {
        $map = [];

        foreach ($this->template()?->layout()->variables() ?? [] as $index => $variable) {
            $placeholder = trim((string) ($this->placeholders[$index] ?? ''));

            if ($placeholder !== '') {
                $map[$variable] = $placeholder;
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function order(): array
    {
        return array_values(array_filter(explode(',', $this->channelOrder), static fn (string $channel): bool => $channel !== ''));
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
