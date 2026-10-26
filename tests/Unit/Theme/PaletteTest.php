<?php

declare(strict_types=1);

use App\Theme\Color;
use App\Theme\Palette;

/*
 * P00-09a: colour scales generated from one hex (PLATFORM_CONSOLE.md § 9.2).
 * The shipped defaults must reproduce the approved design's tokens
 * (docs/design/templates/assets/theme.css) exactly.
 */

it('reproduces the approved primary scale from the default colour', function () {
    expect(Palette::generate('#1D4ED8', Palette::PRIMARY_PROFILE))->toBe([
        50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6',
        600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554',
    ]);
});

it('reproduces the approved accent tokens from the default colour', function () {
    $accent = Palette::generate('#F59E0B', Palette::ACCENT_PROFILE);

    expect($accent[500])->toBe('#f59e0b')
        ->and($accent[300])->toBe('#fcd34d')
        ->and($accent[50])->toBe('#fffbeb');
});

it('reproduces the approved dark brand surfaces', function () {
    expect(Palette::heroDeep('#1D4ED8'))->toBe(['#0f1b44', '#070e27']);
});

it('keeps the chosen colour and builds a light-to-dark scale around it', function (string $hex) {
    $palette = Palette::generate($hex, Palette::PRIMARY_PROFILE);
    $lightness = array_map(fn (string $step): float => Color::toOklch($step)[0], $palette);

    expect($palette)->toHaveCount(11)
        ->and($palette)->toContain(Color::normalize($hex))
        ->and(array_values($lightness))->toBe(array_values(collect($lightness)->sortDesc()->all()));

    foreach ($palette as $step) {
        expect($step)->toMatch('/^#[0-9a-f]{6}$/');
    }
})->with(['#0F766E', '#B91C1C', '#7C3AED', '#0EA5E9', '#16A34A', '#111827', '#FACC15', '#F472B6', '#888888']);

it('keeps white text readable on the dark brand surface for any colour', function (string $hex) {
    [$deep] = Palette::heroDeep($hex);

    expect(Color::contrast($deep, '#ffffff'))->toBeGreaterThanOrEqual(9.0);
})->with(['#1D4ED8', '#FACC15', '#FDE68A', '#22C55E', '#F97316', '#FFFFFF', '#000000', '#6B7280']);

it('keeps greys grey', function () {
    foreach (Palette::generate('#808080', Palette::PRIMARY_PROFILE) as $hex) {
        expect(Color::toOklch($hex)[1])->toBeLessThan(0.01);
    }
});

it('round-trips through OKLCH', function (string $hex) {
    [$l, $c, $h] = Color::toOklch($hex);

    expect(Color::fromOklch($l, $c, $h))->toBe(Color::normalize($hex));
})->with(['#1D4ED8', '#F59E0B', '#0F1B44', '#FFFFFF', '#000000', '#12AB34']);

it('maps colours outside sRGB back inside without changing the hue much', function () {
    $hex = Color::fromOklch(0.6, 0.5, 145.0);
    [, , $hue] = Color::toOklch($hex);

    expect($hex)->toMatch('/^#[0-9a-f]{6}$/')
        ->and(abs($hue - 145.0))->toBeLessThan(3.0);
});

it('measures WCAG contrast', function () {
    expect(round(Color::contrast('#ffffff', '#000000'), 2))->toBe(21.0)
        ->and(round(Color::contrast('#ffffff', '#1D4ED8'), 2))->toBe(6.7)
        ->and(round(Color::contrast('#777777', '#777777'), 2))->toBe(1.0);
});

it('normalises and validates hex', function () {
    expect(Color::normalize('#ABC'))->toBe('#aabbcc')
        ->and(Color::normalize(' #1D4ED8 '))->toBe('#1d4ed8')
        ->and(Color::isHex('#12345'))->toBeFalse()
        ->and(fn () => Color::normalize('blue'))->toThrow(InvalidArgumentException::class);
});
