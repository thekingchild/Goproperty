<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Mappings;

use App\Models\User;
use App\Notifications\Channels\WhatsAppEvent;
use App\Notifications\Channels\WhatsAppEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Enums\TemplateCategory;
use Modules\WhatsApp\Models\ChannelMapping;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Models\WhatsAppTemplate;
use Modules\WhatsApp\Templates\TemplateLayout;

/**
 * The current organization's event → template mapping and channel order
 * (ARCHITECTURE.md § 6.7; DATABASE_SCHEMA.md § 7
 * `notification_channel_mappings`): the only writer apart from
 * TemplateSync switching a mapping off. Owners and administrators
 * (`whatsapp.messages`). Every save is audited under log `whatsapp`.
 *
 * - Only an **approved utility** template may be mapped: GoProperty's
 *   messages are reminders and transactional notices, never marketing.
 * - Every template variable is mapped to one of the event's whitelisted
 *   placeholders (WhatsAppEvent: the values its email and SMS templates
 *   use), and nothing else; values are filled in as plain text.
 * - The channel order holds the event's channels only (WhatsApp, and SMS
 *   for a reminder on an SMS rung), each once.
 * - A mapping is active only with a template and a complete variable map.
 */
final readonly class ChannelMappings
{
    /** Meta refuses a text parameter longer than this. */
    private const int VALUE_MAX = 1000;

    public function __construct(private WhatsAppEvents $events) {}

    public function for(string $event): ?ChannelMapping
    {
        return ChannelMapping::query()->where('event_key', $event)->with('template')->first();
    }

    /**
     * @param  array<string, string>  $variableMap  template variable => placeholder
     * @param  list<string>  $channelOrder
     *
     * @throws MappingRefused
     */
    public function save(User $by, string $eventKey, ?int $templateId, array $variableMap, array $channelOrder, bool $active): ChannelMapping
    {
        Gate::forUser($by)->authorize('manageMessages', WhatsAppConnection::class);
        $event = $this->event($eventKey);
        $template = $templateId !== null ? WhatsAppTemplate::query()->find($templateId) : null;

        if ($templateId !== null && ! $template instanceof WhatsAppTemplate) {
            throw new MappingRefused('templateId', __('Choose one of your synced templates.'));
        }

        $variableMap = $template !== null ? $this->checkedMap($event, $template, $variableMap) : [];
        $channelOrder = $this->checkedOrder($event, $channelOrder);

        if ($template !== null && ! $template->usable()) {
            throw new MappingRefused('templateId', $template->category !== TemplateCategory::Utility
                ? __('Only utility templates can be used: these messages are reminders and notices, never marketing.')
                : __('This template is :status. Choose an approved one.', ['status' => mb_strtolower(__($template->status->label()))]));
        }

        if ($active && $template === null) {
            throw new MappingRefused('templateId', __('Choose a template before switching WhatsApp on for this message.'));
        }

        return DB::transaction(function () use ($by, $event, $template, $variableMap, $channelOrder, $active): ChannelMapping {
            $mapping = ChannelMapping::query()->where('event_key', $event->key)->lockForUpdate()->first() ?? new ChannelMapping;
            $before = $mapping->exists ? ['template_id' => $mapping->whatsapp_template_id, 'active' => $mapping->active, 'channel_order' => $mapping->channel_order] : null;

            $mapping->forceFill([
                'event_key' => $event->key,
                'whatsapp_template_id' => $template?->id,
                'variable_map' => $variableMap,
                'channel_order' => $channelOrder,
                'active' => $active,
                'disabled_reason' => null,
                'updated_by_user_id' => $by->id,
            ])->save();

            activity(WhatsAppConnections::AUDIT_LOG)
                ->event('whatsapp.mapping_saved')
                ->performedOn($mapping)
                ->causedBy($by)
                ->withProperties([
                    'event' => $event->key,
                    'template' => $template?->label(),
                    'variable_map' => $variableMap,
                    'channel_order' => $channelOrder,
                    'active' => $active,
                    'before' => $before,
                ])
                ->log('WhatsApp message settings saved for '.$event->key);

            return $mapping->load('template');
        });
    }

    /**
     * The same template, variables and channel order for the other rungs of
     * a reminder ladder (`reminder.rent_due.t7` → every `reminder.rent_due.*`).
     * Channel orders are kept to what each rung may hold.
     *
     * @return list<string> the events saved
     *
     * @throws MappingRefused
     */
    public function copyToSiblings(User $by, string $eventKey): array
    {
        $mapping = $this->for($eventKey);

        if (! $mapping instanceof ChannelMapping) {
            throw new MappingRefused('event', __('Save this message first.'));
        }

        $saved = [];

        foreach ($this->siblings($eventKey) as $sibling) {
            $order = array_values(array_intersect($mapping->channel_order, $sibling->channels()));
            $this->save($by, $sibling->key, $mapping->whatsapp_template_id, $mapping->variable_map ?? [], $order === [] ? ['whatsapp'] : $order, $mapping->active);
            $saved[] = $sibling->key;
        }

        return $saved;
    }

    /**
     * The other events of the same ladder (same key but the rung).
     *
     * @return list<WhatsAppEvent>
     */
    public function siblings(string $eventKey): array
    {
        $prefix = substr($eventKey, 0, (int) strrpos($eventKey, '.') + 1);

        if (! str_starts_with($eventKey, 'reminder.') || $prefix === '') {
            return [];
        }

        return array_values(array_filter($this->events->all(), static fn (WhatsAppEvent $event): bool => $event->key !== $eventKey && str_starts_with($event->key, $prefix)));
    }

    /**
     * The template's variables filled from a message's placeholder values,
     * as plain one-line text; null when one of them has no value (the
     * message then goes by its next channel).
     *
     * @param  array<string, string>  $variableMap
     * @param  array<string, string|int|null>  $values  placeholder => value
     * @return array<string, string>|null variable => value
     */
    public static function fill(TemplateLayout $layout, array $variableMap, array $values): ?array
    {
        $filled = [];

        foreach ($layout->variables() as $variable) {
            $placeholder = $variableMap[$variable] ?? null;
            $value = $placeholder !== null ? trim((string) preg_replace('/\s+/u', ' ', (string) ($values[$placeholder] ?? ''))) : '';

            if ($value === '') {
                return null;
            }

            $filled[$variable] = mb_substr($value, 0, self::VALUE_MAX);
        }

        return $filled;
    }

    /**
     * The template as the tenant would read it, filled with the event's sample values.
     *
     * @param  array<string, string>  $variableMap
     */
    public function preview(WhatsAppEvent $event, WhatsAppTemplate $template, array $variableMap): string
    {
        $layout = $template->layout();
        $samples = [];

        foreach ($layout->variables() as $variable) {
            $placeholder = $event->placeholder($variableMap[$variable] ?? '');

            if ($placeholder !== null) {
                $samples[$variable] = $placeholder->sample;
            }
        }

        return $layout->render($samples);
    }

    /** @throws MappingRefused */
    public function event(string $key): WhatsAppEvent
    {
        return $this->events->find($key) ?? throw new MappingRefused('event', __('There is no such message.'));
    }

    /**
     * @param  array<string, string>  $variableMap
     * @return array<string, string>
     *
     * @throws MappingRefused
     */
    private function checkedMap(WhatsAppEvent $event, WhatsAppTemplate $template, array $variableMap): array
    {
        $variables = $template->layout()->variables();
        $allowed = $event->placeholderNames();
        $checked = [];

        foreach ($variableMap as $variable => $placeholder) {
            if (! in_array($variable, $variables, true)) {
                throw new MappingRefused('variableMap', __('The template has no variable :variable.', ['variable' => $variable]));
            }

            if (! in_array($placeholder, $allowed, true)) {
                throw new MappingRefused('variableMap.'.$variable, __('Choose one of this message\'s values for :variable.', ['variable' => $template->layout()->describe($variable)]));
            }

            $checked[$variable] = $placeholder;
        }

        foreach ($variables as $variable) {
            if (! isset($checked[$variable])) {
                throw new MappingRefused('variableMap.'.$variable, __('Choose what fills :variable.', ['variable' => $template->layout()->describe($variable)]));
            }
        }

        return $checked;
    }

    /**
     * @param  list<string>  $order
     * @return list<string>
     *
     * @throws MappingRefused
     */
    private function checkedOrder(WhatsAppEvent $event, array $order): array
    {
        if ($order === [] || count(array_unique($order)) !== count($order) || array_diff($order, $event->channels()) !== []) {
            throw new MappingRefused('channelOrder', $event->channels() === ['whatsapp']
                ? __('This message goes by WhatsApp only (never SMS).')
                : __('Choose the order of WhatsApp and SMS for this message.'));
        }

        return $order;
    }
}
