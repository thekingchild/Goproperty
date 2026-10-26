<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * 50–950 colour scales generated from one hex (PLATFORM_CONSOLE.md § 9.2).
 * The chosen colour lands on the step closest to it in lightness; other
 * steps take their lightness, relative chroma and hue drift from a
 * reference scale (Tailwind's blue for primary, amber for the accent), so
 * the shipped defaults reproduce the approved design exactly.
 */
final class Palette
{
    public const array STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /** @var array<int, array{0: float, 1: float, 2: float}> lightness, chroma, hue */
    public const array PRIMARY_PROFILE = [
        50 => [0.9705, 0.0142, 254.6], 100 => [0.9319, 0.0316, 255.6], 200 => [0.8823, 0.0571, 254.1],
        300 => [0.8091, 0.0956, 251.8], 400 => [0.7137, 0.1434, 254.6], 500 => [0.6231, 0.1880, 259.8],
        600 => [0.5461, 0.2152, 262.9], 700 => [0.4882, 0.2172, 264.4], 800 => [0.4244, 0.1809, 265.6],
        900 => [0.3791, 0.1378, 265.5], 950 => [0.2823, 0.0874, 267.9],
    ];

    /** @var array<int, array{0: float, 1: float, 2: float}> */
    public const array ACCENT_PROFILE = [
        50 => [0.9869, 0.0214, 95.3], 100 => [0.9619, 0.0580, 95.6], 200 => [0.9243, 0.1151, 95.7],
        300 => [0.8790, 0.1534, 91.6], 400 => [0.8369, 0.1644, 84.4], 500 => [0.7686, 0.1647, 70.1],
        600 => [0.6658, 0.1574, 58.3], 700 => [0.5553, 0.1455, 49.0], 800 => [0.4732, 0.1247, 46.2],
        900 => [0.4137, 0.1054, 45.9], 950 => [0.2791, 0.0742, 45.6],
    ];

    /** Tailwind neutral scales (PLATFORM_CONSOLE.md § 9.2 `theme.neutral`). */
    /** Page background behind cards for each neutral (the approved design's #F7F8FB for slate). */
    public const array CANVAS = ['slate' => '#f7f8fb', 'gray' => '#f7f8f9', 'zinc' => '#f8f8f9', 'stone' => '#f8f8f6'];

    public const array NEUTRALS = [
        'slate' => [50 => '#f8fafc', 100 => '#f1f5f9', 200 => '#e2e8f0', 300 => '#cbd5e1', 400 => '#94a3b8', 500 => '#64748b', 600 => '#475569', 700 => '#334155', 800 => '#1e293b', 900 => '#0f172a', 950 => '#020617'],
        'gray' => [50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af', 500 => '#6b7280', 600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827', 950 => '#030712'],
        'zinc' => [50 => '#fafafa', 100 => '#f4f4f5', 200 => '#e4e4e7', 300 => '#d4d4d8', 400 => '#a1a1aa', 500 => '#71717a', 600 => '#52525b', 700 => '#3f3f46', 800 => '#27272a', 900 => '#18181b', 950 => '#09090b'],
        'stone' => [50 => '#fafaf9', 100 => '#f5f5f4', 200 => '#e7e5e4', 300 => '#d6d3d1', 400 => '#a8a29e', 500 => '#78716c', 600 => '#57534e', 700 => '#44403c', 800 => '#292524', 900 => '#1c1917', 950 => '#0c0a09'],
    ];

    /**
     * @param  array<int, array{0: float, 1: float, 2: float}>  $profile
     * @return array<int, string> step => hex
     */
    public static function generate(string $hex, array $profile): array
    {
        $hex = Color::normalize($hex);
        [$lightness, $chroma, $hue] = Color::toOklch($hex);
        $anchor = self::closestStep($lightness, $profile);
        [, $anchorChroma, $anchorHue] = $profile[$anchor];

        // Greys stay grey instead of picking up the reference hue.
        $achromatic = $chroma < 0.02;
        $scale = $anchorChroma > 0 ? $chroma / $anchorChroma : 0.0;
        $palette = [];

        foreach ($profile as $step => [$stepLightness, $stepChroma, $stepHue]) {
            $palette[$step] = $step === $anchor
                ? $hex
                : Color::fromOklch($stepLightness, $achromatic ? 0.0 : $stepChroma * $scale, $hue + ($stepHue - $anchorHue));
        }

        return $palette;
    }

    /**
     * The darkest brand surface (hero, login panel): the primary's hue, very
     * dark, and always at least 9:1 against white text (§ 9.2).
     *
     * @return array{0: string, 1: string} --hero-deep, --hero-deep-2
     */
    public static function heroDeep(string $primary): array
    {
        [, $chroma, $hue] = Color::toOklch($primary);
        $achromatic = $chroma < 0.02;
        $lightness = 0.2395;

        do {
            // The approved navy sits a few degrees bluer than the primary.
            $deep = Color::fromOklch($lightness, $achromatic ? 0.0 : min(0.09, $chroma * 0.364), $hue + 3.33);
            $lightness -= 0.01;
        } while (Color::contrast($deep, '#ffffff') < 9 && $lightness > 0);

        return [$deep, Color::fromOklch(0.1743, $achromatic ? 0.0 : min(0.06, $chroma * 0.2417), $hue + 3.88)];
    }

    /** @param  array<int, array{0: float, 1: float, 2: float}>  $profile */
    private static function closestStep(float $lightness, array $profile): int
    {
        $best = 500;
        $distance = INF;

        foreach ($profile as $step => [$stepLightness]) {
            if (abs($stepLightness - $lightness) < $distance) {
                $distance = abs($stepLightness - $lightness);
                $best = $step;
            }
        }

        return $best;
    }
}
