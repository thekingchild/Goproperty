<?php

declare(strict_types=1);

namespace App\Documents;

use RuntimeException;

/**
 * The fonts bundled for documents (BRANDING_DOCUMENTS.md § 5), in
 * public/fonts/documents (built by scripts/fonts/build-document-fonts.php):
 * Inter for text, in two subsets ("Inter" for Latin text, "Inter Ext" for
 * the ₦ sign and other Latin Extended glyphs; both drivers fall back glyph
 * by glyph along the font-family list), DejaVu Sans Mono Bold for codes and
 * DejaVu Serif for the classic layout's headings. DejaVu Sans, which dompdf
 * has built in, stays the last fallback.
 *
 * Both PDF drivers read the same files, so text measures the same:
 * dompdf by path (public/ is inside its chroot), Chrome as data URIs (the
 * HTML is all Gotenberg receives), web pages by URL. dompdf has only the
 * weights registered here, so document styles use only 400, 600, 700 and
 * 800 (a lint test checks the views).
 *
 * Two dompdf habits are worked around so lines measure as in Chrome: it
 * makes a line as tall as the tallest font named in the font-family list,
 * so PDF stacks name only the bundled faces (no generic fallbacks); and it
 * multiplies line-height by the font's own height (ascender − descender)
 * times `pdf.dompdf.font_height_ratio`, which config/pdf.php sets so that
 * Inter's comes to exactly 1 em. DejaVu's is 0.96 em then, so lines set in
 * DejaVu get line-height / 0.962 for dompdf only (lineHeight()).
 */
final class DocumentFonts
{
    public const string SANS_STACK = 'Inter, "Inter Ext"';

    public const string SERIF_STACK = '"DejaVu Serif"';

    public const string MONO_STACK = '"DejaVu Sans Mono"';

    /**
     * dompdf's line height for DejaVu faces in em at line-height 1, with
     * Inter's at 1: (928 + 236) / (969 + 241).
     */
    public const float DOMPDF_DEJAVU_LINE = 0.962;

    /** The font weights document styles may use. */
    public const array WEIGHTS = [400, 600, 700, 800];

    /** Inter's ascender − descender, in thousandths of an em (its .ufm in dompdf's font cache). */
    public const int INTER_HEIGHT = 1210;

    /** @var list<array{family: string, file: string, weight: int, style: string, serif?: bool}> */
    private const array FACES = [
        ['family' => 'Inter', 'file' => 'Inter-400-normal.ttf', 'weight' => 400, 'style' => 'normal'],
        ['family' => 'Inter', 'file' => 'Inter-600-normal.ttf', 'weight' => 600, 'style' => 'normal'],
        ['family' => 'Inter', 'file' => 'Inter-700-normal.ttf', 'weight' => 700, 'style' => 'normal'],
        ['family' => 'Inter', 'file' => 'Inter-800-normal.ttf', 'weight' => 800, 'style' => 'normal'],
        ['family' => 'Inter', 'file' => 'Inter-400-italic.ttf', 'weight' => 400, 'style' => 'italic'],
        ['family' => 'Inter Ext', 'file' => 'InterExt-400-normal.ttf', 'weight' => 400, 'style' => 'normal'],
        ['family' => 'Inter Ext', 'file' => 'InterExt-600-normal.ttf', 'weight' => 600, 'style' => 'normal'],
        ['family' => 'Inter Ext', 'file' => 'InterExt-700-normal.ttf', 'weight' => 700, 'style' => 'normal'],
        ['family' => 'Inter Ext', 'file' => 'InterExt-800-normal.ttf', 'weight' => 800, 'style' => 'normal'],
        ['family' => 'Inter Ext', 'file' => 'InterExt-400-italic.ttf', 'weight' => 400, 'style' => 'italic'],
        ['family' => 'DejaVu Sans Mono', 'file' => 'DejaVuSansMono-Bold.ttf', 'weight' => 700, 'style' => 'normal'],
        ['family' => 'DejaVu Serif', 'file' => 'DejaVuSerif.ttf', 'weight' => 400, 'style' => 'normal', 'serif' => true],
        ['family' => 'DejaVu Serif', 'file' => 'DejaVuSerif-Bold.ttf', 'weight' => 700, 'style' => 'normal', 'serif' => true],
    ];

    /** @var array<string, string> file => data URI, per process */
    private static array $dataUris = [];

    /** The @font-face rules for this target and layout. */
    public static function css(RenderTarget $target, DocumentLayout $layout): string
    {
        $rules = [];

        foreach (self::FACES as $face) {
            if (($face['serif'] ?? false) && ! $layout->usesSerif()) {
                continue;
            }

            $rules[] = sprintf(
                '@font-face { font-family: "%s"; src: url("%s") format("truetype"); font-weight: %d; font-style: %s; }',
                $face['family'],
                self::source($target, $face['file']),
                $face['weight'],
                $face['style'],
            );
        }

        return implode("\n", $rules);
    }

    /** A font stack for this target: web pages add the system fallbacks. */
    public static function stack(string $stack, RenderTarget $target): string
    {
        if ($target->isPdf()) {
            return $stack;
        }

        return $stack.match ($stack) {
            self::SERIF_STACK => ', Georgia, "Times New Roman", serif',
            self::MONO_STACK => ', Menlo, Consolas, monospace',
            default => ', Arial, sans-serif',
        };
    }

    /**
     * The line-height to write for an element set in $stack, so both drivers
     * draw lines $lineHeight em apart.
     */
    public static function lineHeight(float $lineHeight, string $stack, RenderTarget $target): float
    {
        $dejavu = $stack === self::SERIF_STACK || $stack === self::MONO_STACK;

        return round($dejavu && $target === RenderTarget::Dompdf ? $lineHeight / self::DOMPDF_DEJAVU_LINE : $lineHeight, 3);
    }

    /** @return list<string> the bundled files */
    public static function files(): array
    {
        return array_values(array_unique(array_column(self::FACES, 'file')));
    }

    public static function path(string $file): string
    {
        return public_path('fonts/documents/'.$file);
    }

    private static function source(RenderTarget $target, string $file): string
    {
        return match ($target) {
            RenderTarget::Web => asset('fonts/documents/'.$file),
            RenderTarget::Dompdf => self::path($file),
            RenderTarget::Chrome => self::dataUri($file),
        };
    }

    private static function dataUri(string $file): string
    {
        if (! isset(self::$dataUris[$file])) {
            $bytes = @file_get_contents(self::path($file));

            if ($bytes === false) {
                throw new RuntimeException("The document font {$file} is missing from public/fonts/documents.");
            }

            self::$dataUris[$file] = 'data:font/ttf;base64,'.base64_encode($bytes);
        }

        return self::$dataUris[$file];
    }
}
