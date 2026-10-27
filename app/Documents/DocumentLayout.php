<?php

declare(strict_types=1);

namespace App\Documents;

use App\Enums\Concerns\HasValues;

/**
 * The look of every PDF and web document an organization issues
 * (BRANDING_DOCUMENTS.md § 1): modern (default, as in the design
 * templates), classic (serif headings, boxed letterhead) or compact
 * (smaller type for long bills).
 *
 * The three share the markup of each document; the shared stylesheet
 * (resources/views/documents/partials/styles.blade.php) reads the sizes and
 * fonts below, so every document changes with the layout the same way.
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

    /** Body text size in px (CSS px: 96 per inch in both PDF drivers). */
    public function baseFontSize(): float
    {
        return $this === self::Compact ? 10.5 : 12.0;
    }

    /** Organization name and document title. */
    public function headingFontStack(): string
    {
        return $this === self::Classic ? DocumentFonts::SERIF_STACK : DocumentFonts::SANS_STACK;
    }

    /** Whether the bundled serif face is needed (only classic headings use it). */
    public function usesSerif(): bool
    {
        return $this === self::Classic;
    }

    /** Corner radius of panels: classic is square. */
    public function radius(int $px): int
    {
        return $this === self::Classic ? 0 : $px;
    }
}
