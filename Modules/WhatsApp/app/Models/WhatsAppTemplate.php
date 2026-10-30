<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\WhatsApp\Enums\TemplateCategory;
use Modules\WhatsApp\Enums\TemplateStatus;
use Modules\WhatsApp\Templates\TemplateLayout;

/**
 * One of the organization's message templates as Meta last described it
 * (DATABASE_SCHEMA.md § 7 `whatsapp_templates`), organization-scoped.
 * Written only by Templates\TemplateSync; never deleted (a template gone
 * from Meta stays, `disabled`).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $organization_whatsapp_connection_id
 * @property string|null $meta_template_id
 * @property string $name
 * @property string $language
 * @property TemplateCategory $category
 * @property TemplateStatus $status
 * @property string|null $meta_status
 * @property array<int, mixed> $components
 * @property int $variable_count
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WhatsAppTemplate extends Model
{
    use BelongsToOrganization;

    protected $table = 'whatsapp_templates';

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('WhatsApp templates are never deleted: a template gone from Meta is kept as disabled.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
            'status' => TemplateStatus::class,
            'components' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function layout(): TemplateLayout
    {
        return TemplateLayout::fromComponents($this->components);
    }

    /** May be mapped to GoProperty's messages and sent: approved, and a utility template. */
    public function usable(): bool
    {
        return $this->status === TemplateStatus::Approved && $this->category === TemplateCategory::Utility;
    }

    /** "rent_reminder (en_US)". */
    public function label(): string
    {
        return $this->name.' ('.$this->language.')';
    }
}
