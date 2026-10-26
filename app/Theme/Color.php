<?php

declare(strict_types=1);

namespace App\Theme;

use InvalidArgumentException;

/**
 * Colour maths for the platform theme (PLATFORM_CONSOLE.md § 9.2): hex and
 * OKLCH conversion (with gamut mapping back into sRGB) and WCAG contrast.
 */
final class Color
{
    /** "#1D4ED8" or "#1d4ed8" → "#1d4ed8"; three-digit hex is expanded. */
    public static function normalize(string $hex): string
    {
        $hex = strtolower(trim($hex));

        if (preg_match('/^#([0-9a-f]{3})$/D', $hex, $match) === 1) {
            $hex = '#'.$match[1][0].$match[1][0].$match[1][1].$match[1][1].$match[1][2].$match[1][2];
        }

        if (preg_match('/^#[0-9a-f]{6}$/D', $hex) !== 1) {
            throw new InvalidArgumentException("\"{$hex}\" is not a hex colour such as #1D4ED8.");
        }

        return $hex;
    }

    public static function isHex(string $value): bool
    {
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', trim($value)) === 1;
    }

    /** @return array{0: float, 1: float, 2: float} lightness 0–1, chroma, hue in degrees */
    public static function toOklch(string $hex): array
    {
        [$r, $g, $b] = self::toLinearRgb($hex);

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $chroma = sqrt($a * $a + $bb * $bb);
        $hue = rad2deg(atan2($bb, $a));

        return [$lightness, $chroma, $hue < 0 ? $hue + 360 : $hue];
    }

    /**
     * OKLCH to hex. Colours outside sRGB keep their lightness and hue and
     * lose chroma until they fit, so a palette never clips to odd hues.
     */
    public static function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        $lightness = max(0.0, min(1.0, $lightness));
        $low = 0.0;
        $high = max(0.0, $chroma);

        if (self::inGamut($lightness, $high, $hue)) {
            return self::toHex(self::oklchToLinearRgb($lightness, $high, $hue));
        }

        for ($i = 0; $i < 24; $i++) {
            $mid = ($low + $high) / 2;

            if (self::inGamut($lightness, $mid, $hue)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return self::toHex(self::oklchToLinearRgb($lightness, $low, $hue));
    }

    /** WCAG 2 contrast ratio, 1–21. */
    public static function contrast(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::toLinearRgb($hex);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** "rgb" triplet for rgba() overlays: "15, 27, 68". */
    public static function rgbTriplet(string $hex): string
    {
        $hex = self::normalize($hex);

        return hexdec(substr($hex, 1, 2)).', '.hexdec(substr($hex, 3, 2)).', '.hexdec(substr($hex, 5, 2));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function toLinearRgb(string $hex): array
    {
        $hex = self::normalize($hex);
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return [$channels[0], $channels[1], $channels[2]];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function oklchToLinearRgb(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function inGamut(float $lightness, float $chroma, float $hue): bool
    {
        foreach (self::oklchToLinearRgb($lightness, $chroma, $hue) as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    /** @param  array{0: float, 1: float, 2: float}  $linear */
    private static function toHex(array $linear): string
    {
        $hex = '#';

        foreach ($linear as $channel) {
            $channel = max(0.0, min(1.0, $channel));
            $encoded = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * ($channel ** (1 / 2.4)) - 0.055;
            $hex .= str_pad(dechex((int) round(max(0.0, min(1.0, $encoded)) * 255)), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }
}
