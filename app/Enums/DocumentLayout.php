<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The look of every PDF and web document an organization issues
 * (BRANDING_DOCUMENTS.md § 1): modern (default, as in the design
 * templates), classic (serif headings, boxed letterhead) or compact
 * (smaller type for long bills).
 */
enum DocumentLayout: string
{
    use HasValues;

    case Modern = 'modern';
    case Classic = 'classic';
    case Compact = 'compact';

    public function label(): string
    {
        return match ($this) {
            self::Modern => 'Modern',
            self::Classic => 'Classic',
            self::Compact => 'Compact',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Modern => 'Clean headings and soft panels, as in our samples.',
            self::Classic => 'Serif headings and a boxed letterhead.',
            self::Compact => 'Smaller type, for long bills.',
        };
    }
}
