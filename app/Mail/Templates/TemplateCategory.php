<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use App\Enums\Concerns\HasValues;

/**
 * What kind of email a template is (PLATFORM_CONSOLE.md § 5 "Categories"),
 * which decides whether people can turn it off.
 */
enum TemplateCategory: string
{
    use HasValues;

    case Security = 'security';
    case Transactional = 'transactional';
    case Reminder = 'reminder';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Security => 'Security',
            self::Transactional => 'Transactional',
            self::Reminder => 'Reminders',
            self::Marketing => 'Product / marketing',
        };
    }

    public function optOut(): string
    {
        return match ($this) {
            self::Security, self::Transactional => 'Always sent',
            self::Reminder => 'Per-user channel preferences',
            self::Marketing => 'Opt-in only (NDPA consent)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
