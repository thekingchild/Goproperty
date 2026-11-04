<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Privacy\Consents\ConsentDocument;
use App\Theme\Theme;
use App\Theme\ThemeManager;
use InvalidArgumentException;

/**
 * The privacy policy and terms of service (P09-02; PLATFORM_CONSOLE.md
 * § 9.3 `site.privacy_policy`, `site.terms`): their text is website content,
 * drafted and published in Console → Settings → Public website. A version
 * is the start of the SHA-256 of the published text, so a consent record
 * names exactly the wording that was shown, and any change, however small,
 * is a new version (docs/plans/P09-subtasks.md default 3).
 */
final readonly class LegalDocuments
{
    public const array KEYS = [
        'privacy' => 'site.privacy_policy',
        'terms' => 'site.terms',
    ];

    public function __construct(private ThemeManager $themes) {}

    public function text(ConsentDocument $document, ?Theme $theme = null): string
    {
        return ($theme ?? $this->themes->published())->string($this->key($document));
    }

    /** "v-1a2b3c4d5e6f" for the published text, or for the theme given (the console's preview). */
    public function version(ConsentDocument $document, ?Theme $theme = null): string
    {
        return 'v-'.substr(hash('sha256', $this->text($document, $theme)), 0, 12);
    }

    private function key(ConsentDocument $document): string
    {
        return match ($document) {
            ConsentDocument::Privacy => self::KEYS['privacy'],
            ConsentDocument::Terms => self::KEYS['terms'],
            ConsentDocument::MarketingEmail => throw new InvalidArgumentException('Product email has no published text.'),
        };
    }
}
