<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One of GoProperty's email templates (P05-04; PLATFORM_CONSOLE.md § 5,
 * DATABASE_SCHEMA.md "email_templates"). Platform-level, no organization.
 * Its wording changes only through App\Mail\Templates\EmailTemplates,
 * which adds a version; never deleted.
 *
 * @property int $id
 * @property string $key
 * @property TemplateChannel $channel
 * @property string $name
 * @property string|null $description
 * @property TemplateCategory $category
 * @property list<string> $allowed_placeholders
 * @property int|null $current_version_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EmailTemplateVersion|null $currentVersion
 */
class EmailTemplate extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Email templates are never deleted; switch one off instead.'));
    }

    /** @return BelongsTo<EmailTemplateVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(EmailTemplateVersion::class, 'current_version_id');
    }

    /** @return HasMany<EmailTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(EmailTemplateVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => TemplateChannel::class,
            'category' => TemplateCategory::class,
            'allowed_placeholders' => 'array',
            'active' => 'boolean',
        ];
    }
}
