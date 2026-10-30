<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Enums;

/**
 * A template's approval at Meta (DATABASE_SCHEMA.md § 7
 * `whatsapp_templates.status`). Only an approved template is sent; a sync
 * that finds one paused, rejected or disabled switches its mappings off
 * (ARCHITECTURE.md § 6.7). Meta's other words (IN_APPEAL, PENDING_DELETION,
 * DELETED, LIMIT_EXCEEDED, …) count as pending or disabled; the raw word is
 * kept in `meta_status`.
 */
enum TemplateStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Disabled = 'disabled';

    public static function fromMeta(string $status): self
    {
        return match (strtoupper(trim($status))) {
            'APPROVED' => self::Approved,
            'PENDING', 'IN_APPEAL' => self::Pending,
            'REJECTED' => self::Rejected,
            'PAUSED' => self::Paused,
            default => self::Disabled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Pending => 'Waiting for Meta',
            self::Rejected => 'Rejected by Meta',
            self::Paused => 'Paused by Meta',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'green',
            self::Pending => 'amber',
            self::Rejected, self::Paused, self::Disabled => 'red',
        };
    }
}
