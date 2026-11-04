<?php

declare(strict_types=1);

namespace App\Privacy\Consents;

use App\Enums\Concerns\HasValues;

/** What a consent record is about (`consent_records.document`). */
enum ConsentDocument: string
{
    use HasValues;

    case Terms = 'terms';
    case Privacy = 'privacy';
    /** Product news and announcements by email: opt-in only (PLATFORM_CONSOLE.md § 5 "Categories"). */
    case MarketingEmail = 'marketing_email';

    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Terms of service',
            self::Privacy => 'Privacy policy',
            self::MarketingEmail => 'Product news by email',
        };
    }
}
