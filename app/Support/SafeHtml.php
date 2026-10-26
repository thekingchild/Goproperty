<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans HTML typed into the console (FAQ answers, legal pages; PLATFORM_CONSOLE.md
 * § 9.3): text formatting, headings, lists, tables and https/mailto links only.
 * Scripts, styles, event handlers, iframes and other schemes are dropped.
 */
final class SafeHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(string $html): string
    {
        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowElement('p')->allowElement('br')->allowElement('strong')->allowElement('b')
                ->allowElement('em')->allowElement('i')->allowElement('u')->allowElement('s')
                ->allowElement('h2')->allowElement('h3')->allowElement('h4')
                ->allowElement('ul')->allowElement('ol')->allowElement('li')
                ->allowElement('blockquote')->allowElement('hr')->allowElement('code')
                ->allowElement('table')->allowElement('thead')->allowElement('tbody')->allowElement('tr')
                ->allowElement('th', ['scope'])->allowElement('td')
                ->allowElement('a', ['href', 'title'])
                ->allowLinkSchemes(['https', 'mailto'])
                ->allowRelativeLinks()
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                ->withMaxInputLength(100_000),
        );
    }
}
