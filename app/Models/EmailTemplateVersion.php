<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\Templates\TemplateContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A saved wording of an email template (P05-04): written once by
 * App\Mail\Templates\EmailTemplates, never changed or deleted. A rollback
 * is a new version copying an old one (`restored_from_version`).
 *
 * @property int $id
 * @property int $email_template_id
 * @property int $version
 * @property string $subject
 * @property string $body_markdown
 * @property string|null $action_label
 * @property int|null $restored_from_version
 * @property int|null $created_by_staff_id
 * @property Carbon|null $created_at
 * @property-read EmailTemplate $template
 * @property-read PlatformStaff|null $createdBy
 */
class EmailTemplateVersion extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A template version is never changed; save a new one.'));
        self::deleting(static fn (): never => throw new LogicException('Template versions are never deleted.'));
    }

    public function content(): TemplateContent
    {
        return new TemplateContent($this->subject, $this->body_markdown, $this->action_label);
    }

    /** @return BelongsTo<EmailTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'created_by_staff_id');
    }
}
