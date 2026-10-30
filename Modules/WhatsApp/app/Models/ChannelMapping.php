<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the organization chose for one event (DATABASE_SCHEMA.md § 7
 * `notification_channel_mappings`), organization-scoped: the phone
 * channels in order, the WhatsApp template and its variable map
 * (template variable, e.g. `body.1`, → placeholder, e.g. `bill.owed`).
 * Written only by Mappings\ChannelMappings (owners and administrators,
 * `whatsapp.messages`) and Templates\TemplateSync (switches it off with a
 * `disabled_reason` when Meta paused, rejected or disabled the template).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $event_key
 * @property list<string> $channel_order
 * @property int|null $whatsapp_template_id
 * @property array<string, string>|null $variable_map
 * @property bool $active
 * @property string|null $disabled_reason
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WhatsAppTemplate|null $template
 */
final class ChannelMapping extends Model
{
    use BelongsToOrganization;

    protected $table = 'notification_channel_mappings';

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel_order' => 'array',
            'variable_map' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<WhatsAppTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }
}
