<?php

declare(strict_types=1);

namespace App\Branding;

use App\Theme\Color;

/**
 * Contrast rules and derived shades for an organization's document colours
 * (BRANDING_DOCUMENTS.md § 1), with the WCAG maths of the platform theme
 * (App\Theme\Color).
 *
 * - The primary colour is the brand bar, the table headers and the
 *   amount-due panel on white paper: below 3:1 against white (WCAG's
 *   minimum for large text and graphics) it is rejected, because no
 *   shade of it would read as the brand any more.
 * - Between 3:1 and 4.5:1 it is accepted, and text on it (or in it) uses a
 *   darker shade of the same hue that reaches 4.5:1 (textShade()).
 * - The accent marks highlights and badges next to the primary: it must
 *   stand apart from it by 3:1.
 */
final class BrandColours
{
    public const float MIN_AGAINST_WHITE = 3.0;

    public const float TEXT_AGAINST_WHITE = 4.5;

    public const float MIN_ACCENT_AGAINST_PRIMARY = 3.0;

    /**
     * What is wrong with the pair, by field, in words for the person
     * choosing; empty when both can be used.
     *
     * @return array<'primaryColor'|'accentColor', string>
     */
    public static function problems(string $primary, ?string $accent): array
    {
        $problems = [];

        if (! Color::isHex($primary)) {
            return ['primaryColor' => 'Choose a colour such as #0E6B5C.'];
        }

        $onWhite = Color::contrast($primary, '#ffffff');

        if ($onWhite < self::MIN_AGAINST_WHITE) {
            $problems['primaryColor'] = sprintf(
                '%s is too light: against white paper it reaches %.1f:1, and at least %.1f:1 is needed so the brand bar, headings and table headers stay readable. Choose a darker colour.',
                strtoupper(Color::normalize($primary)),
                floor($onWhite * 10) / 10,
                self::MIN_AGAINST_WHITE,
            );
        }

        if ($accent !== null && $accent !== '') {
            if (! Color::isHex($accent)) {
                $problems['accentColor'] = 'Choose a colour such as #F2B53A, or leave it empty.';
            } elseif (Color::contrast($accent, $primary) < self::MIN_ACCENT_AGAINST_PRIMARY) {
                $problems['accentColor'] = sprintf(
                    'The accent %s is too close to the primary colour (%.1f:1; at least %.1f:1 is needed so highlights stand out). Choose a lighter or darker accent.',
                    strtoupper(Color::normalize($accent)),
                    floor(Color::contrast($accent, $primary) * 10) / 10,
                    self::MIN_ACCENT_AGAINST_PRIMARY,
                );
            }
        }

        return $problems;
    }

    /** The primary itself when white text on it reaches 4.5:1, else the nearest darker shade that does. */
    public static function textShade(string $primary): string
    {
        $primary = Color::normalize($primary);

        if (Color::contrast($primary, '#ffffff') >= self::TEXT_AGAINST_WHITE) {
            return $primary;
        }

        [$lightness, $chroma, $hue] = Color::toOklch($primary);
        $shade = $primary;

        while ($lightness > 0 && Color::contrast($shade, '#ffffff') < self::TEXT_AGAINST_WHITE) {
            $lightness -= 0.01;
            $shade = Color::fromOklch($lightness, $chroma, $hue);
        }

        return $shade;
    }

    /** $amount (0–1) of $hex over white: the soft panels behind letterhead cards. */
    public static function tint(string $hex, float $amount): string
    {
        return self::mix($hex, '#ffffff', $amount);
    }

    /** $weight (0–1) of $first mixed into $second, channel by channel. */
    public static function mix(string $first, string $second, float $weight): string
    {
        $a = sscanf(ltrim(Color::normalize($first), '#'), '%2x%2x%2x') ?? [0, 0, 0];
        $b = sscanf(ltrim(Color::normalize($second), '#'), '%2x%2x%2x') ?? [0, 0, 0];
        $weight = max(0.0, min(1.0, $weight));
        $hex = '#';

        foreach ([0, 1, 2] as $channel) {
            $value = (int) round(((int) ($a[$channel] ?? 0)) * $weight + ((int) ($b[$channel] ?? 0)) * (1 - $weight));
            $hex .= str_pad(dechex(max(0, min(255, $value))), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /** White or near-black, whichever reads better on $hex (text on the accent). */
    public static function readableOn(string $hex): string
    {
        return Color::contrast('#ffffff', $hex) >= Color::contrast('#0f172a', $hex) ? '#ffffff' : '#0f172a';
    }
}
