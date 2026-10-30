<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Templates;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Connections\WhatsAppConnections;
use Modules\WhatsApp\Enums\TemplateCategory;
use Modules\WhatsApp\Enums\TemplateStatus;
use Modules\WhatsApp\Meta\CloudApi;
use Modules\WhatsApp\Meta\MetaRefused;
use Modules\WhatsApp\Meta\MetaUnavailable;
use Modules\WhatsApp\Models\ChannelMapping;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Models\WhatsAppTemplate;

/**
 * Fetches the current organization's message templates from Meta
 * (ARCHITECTURE.md § 6.7: `GET /{waba-id}/message_templates`, daily and on
 * demand) and keeps them in `whatsapp_templates`, the only writer there.
 *
 * - A template is matched by name and language; Meta's status and category
 *   are taken as they are. One gone from Meta's list is kept as `disabled`.
 * - **On the same sync**, every active mapping whose template is no longer
 *   usable (paused, rejected, disabled, pending again, moved out of the
 *   utility category) or whose variables changed is switched off, with the
 *   reason, so that event goes by its next channel (SMS for reminders)
 *   from then on. Audited under log `whatsapp`.
 *
 * Meta refusing the token marks the connection failing. Safe to run again:
 * a sync that changes nothing writes nothing but the sync time.
 */
final readonly class TemplateSync
{
    public function __construct(
        private CloudApi $meta,
        private WhatsAppConnections $connections,
    ) {}

    public function sync(WhatsAppConnection $connection, ?User $by = null): SyncResult
    {
        try {
            $fetched = $this->meta->templates($connection);
        } catch (MetaRefused $refused) {
            if ($refused->tokenRefused) {
                $this->connections->markFailing($connection, $refused->getMessage());
            }

            return $this->failed($connection, $refused->getMessage());
        } catch (MetaUnavailable $unavailable) {
            return $this->failed($connection, $unavailable->getMessage());
        }

        if ($fetched === null) {
            return new SyncResult(false);
        }

        return DB::transaction(function () use ($connection, $fetched, $by): SyncResult {
            $now = CarbonImmutable::now();
            $seen = [];

            foreach ($fetched as $raw) {
                $template = $this->keep($connection, $raw, $now);

                if ($template !== null) {
                    $seen[] = $template->id;
                }
            }

            // Gone from Meta (deleted there): kept, disabled.
            WhatsAppTemplate::query()
                ->where('organization_whatsapp_connection_id', $connection->id)
                ->whereNotIn('id', $seen === [] ? [0] : $seen)
                ->where('status', '!=', TemplateStatus::Disabled->value)
                ->get()
                ->each(function (WhatsAppTemplate $template) use ($now): void {
                    $template->forceFill(['status' => TemplateStatus::Disabled, 'meta_status' => 'DELETED', 'synced_at' => $now])->save();
                });

            $disabled = $this->switchOffUnusable($by);

            WhatsAppConnection::query()->whereKey($connection->id)->update(['templates_synced_at' => $now, 'last_sync_error' => null]);

            return new SyncResult(true, count($seen), $disabled);
        }, 3);
    }

    /** @param  array<string, mixed>  $raw */
    private function keep(WhatsAppConnection $connection, array $raw, CarbonImmutable $now): ?WhatsAppTemplate
    {
        $name = is_string($raw['name'] ?? null) ? mb_substr(trim($raw['name']), 0, 512) : '';
        $language = is_string($raw['language'] ?? null) ? mb_substr(trim($raw['language']), 0, 16) : '';

        if ($name === '' || $language === '') {
            return null;
        }

        $components = is_array($raw['components'] ?? null) ? array_values($raw['components']) : [];
        $template = WhatsAppTemplate::query()->where('name', $name)->where('language', $language)->lockForUpdate()->first() ?? new WhatsAppTemplate;
        $template->forceFill([
            'organization_whatsapp_connection_id' => $connection->id,
            'meta_template_id' => is_scalar($raw['id'] ?? null) ? mb_substr((string) $raw['id'], 0, 40) : null,
            'name' => $name,
            'language' => $language,
            'category' => TemplateCategory::fromMeta((string) ($raw['category'] ?? '')),
            'status' => TemplateStatus::fromMeta((string) ($raw['status'] ?? '')),
            'meta_status' => is_string($raw['status'] ?? null) ? mb_substr($raw['status'], 0, 40) : null,
            'components' => $components,
            'variable_count' => count(TemplateLayout::fromComponents($components)->variables()),
            'synced_at' => $now,
        ])->save();

        return $template;
    }

    /**
     * Active mappings whose template cannot be sent any more, switched off.
     *
     * @return list<string> their events
     */
    private function switchOffUnusable(?User $by): array
    {
        $disabled = [];

        $mappings = ChannelMapping::query()->where('active', true)->whereNotNull('whatsapp_template_id')->with('template')->lockForUpdate()->get();

        foreach ($mappings as $mapping) {
            $reason = self::unusable($mapping);

            if ($reason === null) {
                continue;
            }

            $mapping->forceFill(['active' => false, 'disabled_reason' => $reason])->save();
            $disabled[] = $mapping->event_key;

            $activity = activity(WhatsAppConnections::AUDIT_LOG)
                ->event('whatsapp.mapping_disabled')
                ->performedOn($mapping)
                ->withProperties(['event' => $mapping->event_key, 'template' => $mapping->template?->label(), 'reason' => $reason]);

            if ($by !== null) {
                $activity->causedBy($by);
            }

            $activity->log('WhatsApp template for '.$mapping->event_key.' switched off: '.$reason);
        }

        return $disabled;
    }

    /** Why the mapping's template can no longer be sent (`template_paused`, `template_changed`, …), or null. */
    public static function unusable(ChannelMapping $mapping): ?string
    {
        $template = $mapping->template;

        if (! $template instanceof WhatsAppTemplate) {
            return 'template_missing';
        }

        if ($template->status !== TemplateStatus::Approved) {
            return 'template_'.$template->status->value;
        }

        if ($template->category !== TemplateCategory::Utility) {
            return 'template_not_utility';
        }

        $mapped = array_keys($mapping->variable_map ?? []);
        $variables = $template->layout()->variables();
        sort($mapped);
        sort($variables);

        return $mapped === $variables ? null : 'template_changed';
    }

    private function failed(WhatsAppConnection $connection, string $error): SyncResult
    {
        WhatsAppConnection::query()->whereKey($connection->id)->update(['last_sync_error' => mb_substr($error, 0, 300)]);

        return new SyncResult(true, error: $error);
    }
}
