<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/** Why a member reports a post or comment (`group_reports.reason`; P07-04). */
enum ReportReason: string
{
    use HasValues;

    case Spam = 'spam';
    case Abuse = 'abuse';
    case Private = 'private_details';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => __('Spam or advertising'),
            self::Abuse => __('Abusive, threatening or harassing'),
            self::Private => __('Shares someone\'s private details'),
            self::Other => __('Something else'),
        };
    }
}
