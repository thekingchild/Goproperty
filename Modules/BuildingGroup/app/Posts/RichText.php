<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Posts;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The rich text of Building Group posts and comments (ARCHITECTURE.md
 * § 6.8 "sanitized rich text (limited toolbar: bold, italic, lists,
 * links)"; ARCHITECTURE.md § 10; P07-02). An allowlist, through
 * symfony/html-sanitizer, which parses the HTML as a browser does and
 * writes out only what it allows, escaped:
 *
 * - kept: paragraphs and line breaks, bold (`strong`, `b`), italic (`em`,
 *   `i`), bulleted and numbered lists, and links whose address is http,
 *   https or mailto — opened in a new tab with
 *   `rel="nofollow noopener noreferrer ugc"`. No attribute but a link's
 *   `href` survives: no `on…` handlers, no `style`, no `class`, no `id`.
 * - unwrapped, their text kept: a short list of harmless wrappers from
 *   pastes (`span`, `font`, `u`, table cells, …); a browser's `div` lines
 *   and pasted headings become paragraphs first.
 * - dropped with everything inside: anything else — scripts, styles,
 *   frames, objects, SVG, MathML, forms and their fields, images and other
 *   media (images come only as uploads), the head's elements, unknown tags.
 * - a link whose address is refused loses its tag and keeps its words.
 *
 * Used on the way in (Posts\GroupPosts stores only clean()'s output) and
 * again on the way out (the feed shows clean() of what is stored), so a
 * body that reached the table any other way is neutralized too.
 */
final class RichText
{
    /** Bytes of HTML accepted for one post or comment, before cleaning; more is refused, never cut. */
    public const int MAX_INPUT_BYTES = 20_000;

    /**
     * Harmless wrappers a paste or an editor brings: unwrapped, their text
     * kept. Any element neither allowed nor listed here is dropped with
     * everything inside it (scripts, styles, frames, objects, SVG, MathML,
     * forms, images and other media, the head's elements, unknown tags).
     */
    private const array UNWRAPPED = [
        'span', 'font', 'u', 's', 'strike', 'sub', 'sup', 'small', 'big', 'mark', 'code', 'kbd', 'samp', 'var',
        'abbr', 'cite', 'q', 'del', 'ins', 'time', 'address', 'center', 'main', 'nav', 'aside', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'dl', 'dt', 'dd', 'details', 'summary',
    ];

    /** Block elements that become paragraphs before cleaning, so their lines stay apart. */
    private const string PARAGRAPHS = 'div|h[1-6]|blockquote|pre|section|article|header|footer';

    private ?HtmlSanitizer $sanitizer = null;

    /** The clean HTML, or null when nothing readable is left. */
    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Browsers' editors write a line as <div>, pastes bring headings: keep the lines as paragraphs.
        $html = (string) preg_replace(['/<(?:'.self::PARAGRAPHS.')\b[^>]*>/i', '/<\/(?:'.self::PARAGRAPHS.')\s*>/i'], ['<p>', '</p>'], $html);
        $clean = $this->sanitizer()->sanitize($html);

        // Empty paragraphs and list items, and line breaks at the edges, from editors.
        $clean = (string) preg_replace('/<(p|li|strong|b|em|i)>(\s|&nbsp;|&#160;|<br \/>)*<\/\1>/u', '', $clean);
        $clean = (string) preg_replace('/<(ul|ol)>\s*<\/\1>/u', '', $clean);
        // A link whose address was refused is plain text.
        $clean = (string) preg_replace('/<a rel="[^"]*" target="_blank">(.*?)<\/a>/su', '$1', $clean);
        $clean = (string) preg_replace('/^(\s|<br \/>)+|(\s|<br \/>)+$/u', '', $clean);
        $clean = trim($clean);

        return self::text($clean) === '' ? null : $clean;
    }

    /** The words of clean HTML as plain text: line breaks between blocks, entities decoded, spaces tidied. */
    public static function text(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $text = (string) preg_replace('/<br \/>|<\/(p|li)>/u', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }

    /** Characters of readable text in clean HTML (what the length limits count). */
    public static function length(?string $html): int
    {
        return mb_strlen(self::text($html));
    }

    private function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer instanceof HtmlSanitizer) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->defaultAction(HtmlSanitizerAction::Drop)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer ugc')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(self::MAX_INPUT_BYTES);

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
