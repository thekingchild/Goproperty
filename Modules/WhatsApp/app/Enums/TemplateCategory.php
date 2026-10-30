<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Enums;

/**
 * Meta's template categories. GoProperty's messages are reminders and
 * transactional notices, so only `utility` templates may be mapped
 * (never marketing, never authentication: GoProperty sends no codes).
 */
enum TemplateCategory: string
{
    case Utility = 'utility';
    case Marketing = 'marketing';
    case Authentication = 'authentication';

    public static function fromMeta(string $category): self
    {
        return self::tryFrom(strtolower(trim($category))) ?? self::Marketing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Utility => 'Utility',
            self::Marketing => 'Marketing',
            self::Authentication => 'Authentication',
        };
    }
}
