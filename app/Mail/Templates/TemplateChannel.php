<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use App\Enums\Concerns\HasValues;

/**
 * How a template is sent (P05-02): an email (Markdown, subject, button), or
 * one SMS page (plain GSM text of at most 160 characters with the longest
 * values; only transactional and reminder messages: never promotional,
 * docs/plans/sms-provider-2026-10-29.md decision 4).
 */
enum TemplateChannel: string
{
    use HasValues;

    case Email = 'email';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Sms => 'SMS',
        };
    }
}
