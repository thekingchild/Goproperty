<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * One version of the platform theme, website and SEO settings
 * (PLATFORM_CONSOLE.md § 9), with everything derived from it: colour
 * scales, the dark brand surfaces and the CSS variables printed in <head>.
 * Built from published values for everyone, or from the draft inside the
 * console's preview.
 */
final readonly class Theme
{
    public const array FONTS = [
        'inter' => 'Inter',
        'plus-jakarta-sans' => 'Plus Jakarta Sans',
        'manrope' => 'Manrope',
        'dm-sans' => 'DM Sans',
    ];

    /** Corner radii: small, default, large (px). */
    public const array RADII = [
        'sharp' => [4, 6, 10],
        'rounded' => [8, 14, 22],
        'extra-rounded' => [12, 20, 30],
    ];

    /**
     * @param  array<string, mixed>  $values  theme.*, site.* and seo.* keys
     */
    public function __construct(private array $values) {}

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function name(): string
    {
        return $this->string('theme.name', 'GoProperty');
    }

    public function primary(): string
    {
        return Color::normalize($this->string('theme.color_primary', '#1D4ED8'));
    }

    public function accent(): string
    {
        return Color::normalize($this->string('theme.color_accent', '#F59E0B'));
    }

    /** @return array<int, string> */
    public function primaryPalette(): array
    {
        return Palette::generate($this->primary(), Palette::PRIMARY_PROFILE);
    }

    /** @return array<int, string> */
    public function accentPalette(): array
    {
        return Palette::generate($this->accent(), Palette::ACCENT_PROFILE);
    }

    /** @return array<int, string> */
    public function neutralPalette(): array
    {
        return Palette::NEUTRALS[$this->string('theme.neutral', 'slate')] ?? Palette::NEUTRALS['slate'];
    }

    public function fontAlias(): string
    {
        $font = $this->string('theme.font', 'inter');

        return array_key_exists($font, self::FONTS) ? $font : 'inter';
    }

    public function fontFamily(): string
    {
        return self::FONTS[$this->fontAlias()];
    }

    /**
     * Every theme token as a CSS custom property. The names match the
     * approved designs (docs/design/templates/assets/theme.css), except the
     * accent (`--color-brand-accent`, because Flux uses `--color-accent` for
     * its interactive colour) and radii (`--gp-radius*`, because Tailwind
     * owns `--radius-*`).
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $primary = $this->primaryPalette();
        $accent = $this->accentPalette();
        $neutral = $this->neutralPalette();
        [$deep, $deeper] = Palette::heroDeep($this->primary());
        $radii = self::RADII[$this->string('theme.radius', 'rounded')] ?? self::RADII['rounded'];

        $variables = [];

        foreach ($primary as $step => $hex) {
            $variables["--color-primary-{$step}"] = $hex;
        }

        return $variables + [
            '--color-brand-accent' => $this->accent(),
            '--color-brand-accent-soft' => $accent[300],
            '--color-brand-accent-50' => $accent[50],
            '--color-brand-accent-200' => $accent[200],
            '--color-brand-accent-700' => $accent[700],

            '--hero-deep' => $deep,
            '--hero-deep-2' => $deeper,
            '--hero-overlay' => $this->overlay('theme.hero_overlay_color', $deep),
            '--hero-overlay-opacity' => $this->opacity('theme.hero_overlay_opacity', 60),
            '--auth-overlay' => $this->overlay('theme.auth_overlay_color', $deep),
            '--auth-overlay-opacity' => $this->opacity('theme.auth_overlay_opacity', 65),

            '--ink' => $neutral[900],
            '--ink-2' => $neutral[800],
            '--muted' => $neutral[500],
            '--muted-2' => $neutral[600],
            '--muted-soft' => $neutral[400],
            '--border' => $neutral[200],
            '--border-strong' => $neutral[300],
            '--canvas' => Palette::CANVAS[$this->string('theme.neutral', 'slate')] ?? Palette::CANVAS['slate'],
            '--surface' => '#ffffff',

            '--font-ui' => '"'.$this->fontFamily().'", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
            '--gp-radius-sm' => $radii[0].'px',
            '--gp-radius' => $radii[1].'px',
            '--gp-radius-lg' => $radii[2].'px',
        ];
    }

    /** The :root rule printed in <head>. */
    public function css(): string
    {
        $declarations = '';

        foreach ($this->cssVariables() as $name => $value) {
            $declarations .= "{$name}:{$value};";
        }

        return ':root{'.$declarations.'}';
    }

    /** For <meta name="theme-color">. */
    public function themeColor(): string
    {
        return Palette::heroDeep($this->primary())[0];
    }

    private function overlay(string $key, string $fallback): string
    {
        $value = $this->get($key);

        return is_string($value) && Color::isHex($value) ? Color::normalize($value) : $fallback;
    }

    private function opacity(string $key, int $default): string
    {
        $value = $this->get($key);
        $percent = is_int($value) ? max(0, min(100, $value)) : $default;

        return rtrim(rtrim(number_format($percent / 100, 2, '.', ''), '0'), '.') ?: '0';
    }
}
