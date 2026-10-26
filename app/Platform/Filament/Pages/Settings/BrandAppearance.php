<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Theme\BrandAssets;
use App\Theme\Color;
use App\Theme\Palette;
use App\Theme\Theme;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Brand & appearance (PLATFORM_CONSOLE.md § 9.2): identity,
 * logos and icons, colours, font, corners, and the landing and sign-in
 * images. Drafted with the theme.
 */
final class BrandAppearance extends DraftSettingsPage
{
    protected static ?string $slug = 'settings/brand';

    protected static ?string $title = 'Brand & appearance';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    public static function permission(): string
    {
        return 'settings.theme';
    }

    public static function settingKeys(): array
    {
        return [
            'theme.name', 'theme.short_name', 'theme.tagline', 'theme.legal_name', 'theme.address',
            'theme.support_email', 'theme.support_phone', 'theme.support_whatsapp',
            'theme.logo_light', 'theme.logo_dark', 'theme.logo_mode', 'theme.icon', 'theme.favicon',
            'theme.color_primary', 'theme.color_accent', 'theme.neutral', 'theme.font', 'theme.radius',
            'theme.hero_image', 'theme.hero_overlay_color', 'theme.hero_overlay_opacity',
            'theme.auth_image', 'theme.auth_overlay_color', 'theme.auth_overlay_opacity', 'theme.auth_show_activity',
        ];
    }

    protected function fields(): array
    {
        return [
            Section::make('Identity')
                ->description('Used in titles, the footer, emails, platform invoices and search results.')
                ->columns(2)
                ->schema([
                    TextInput::make('theme.name')->label('Product name')->required()->maxLength(120),
                    TextInput::make('theme.short_name')->label('Short name')->required()->maxLength(30)->helperText('Home-screen and small spaces.'),
                    TextInput::make('theme.tagline')->label('Tagline')->required()->maxLength(160)->columnSpanFull(),
                    TextInput::make('theme.legal_name')->label('Legal name')->maxLength(160),
                    TextInput::make('theme.address')->label('Address')->maxLength(255),
                    TextInput::make('theme.support_email')->label('Support email')->email()->maxLength(254),
                    TextInput::make('theme.support_phone')->label('Support phone')->tel()->placeholder('+234…'),
                    TextInput::make('theme.support_whatsapp')->label('Support WhatsApp')->tel()->placeholder('+234…'),
                ]),
            Section::make('Logos and icons')
                ->description('Without uploads, a mark is generated from your colours.')
                ->columns(2)
                ->schema([
                    $this->imageUpload('logo-light', 'Logo for light backgrounds', 'Footer, console and emails. PNG, JPG, WebP or SVG, up to 2 MB; at least 480 × 120 recommended.'),
                    $this->imageUpload('logo-dark', 'Logo for dark backgrounds', 'Landing navigation and the sign-in panel. Same rules.'),
                    Select::make('theme.logo_mode')->label('Show with the logo')->required()
                        ->options(['logo_with_text' => 'Logo and product name', 'logo_only' => 'Logo only']),
                    $this->imageUpload('icon', 'App icon', 'Square PNG, WebP or SVG, at least 512 × 512, up to 1 MB. Used for home-screen icons.'),
                    $this->imageUpload('favicon', 'Favicon', 'PNG, SVG or ICO, at least 48 × 48, up to 1 MB. Falls back to the app icon.'),
                ]),
            Section::make('Colours and type')
                ->description('Full colour scales are generated from each colour; the console and the customer app follow them.')
                ->columns(2)
                ->schema([
                    ColorPicker::make('theme.color_primary')->label('Primary colour')->required()->hex(),
                    ColorPicker::make('theme.color_accent')->label('Accent colour')->required()->hex(),
                    Select::make('theme.neutral')->label('Greys')->required()
                        ->options(['slate' => 'Slate (cool)', 'gray' => 'Gray', 'zinc' => 'Zinc (neutral)', 'stone' => 'Stone (warm)']),
                    Select::make('theme.font')->label('Font')->required()->options(Theme::FONTS),
                    Select::make('theme.radius')->label('Corners')->required()
                        ->options(['sharp' => 'Sharp', 'rounded' => 'Rounded', 'extra-rounded' => 'Extra rounded']),
                ]),
            Section::make('Landing page hero')
                ->description('Without a photo the hero shows the brand gradient and skyline art.')
                ->columns(2)
                ->schema([
                    $this->imageUpload('hero', 'Hero photo', 'JPG, PNG or WebP, up to 5 MB; 2400 × 1350 landscape recommended with the subject on the right. Converted to WebP.')
                        ->columnSpanFull(),
                    ColorPicker::make('theme.hero_overlay_color')->label('Overlay colour')->hex()->helperText('Leave empty to follow the brand.'),
                    TextInput::make('theme.hero_overlay_opacity')->label('Overlay strength')->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                ]),
            Section::make('Sign-in page panel')
                ->columns(2)
                ->schema([
                    $this->imageUpload('auth', 'Sign-in photo', 'JPG, PNG or WebP, up to 5 MB; 1200 × 1600 portrait recommended. Converted to WebP.')
                        ->columnSpanFull(),
                    ColorPicker::make('theme.auth_overlay_color')->label('Overlay colour')->hex()->helperText('Leave empty to follow the brand.'),
                    TextInput::make('theme.auth_overlay_opacity')->label('Overlay strength')->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                    Toggle::make('theme.auth_show_activity')->label('Show the "Payment confirmed / Receipt sent" cards'),
                ]),
        ];
    }

    protected function fromFormValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'theme.auth_show_activity' => (bool) $value,
            'theme.color_primary', 'theme.color_accent', 'theme.hero_overlay_color', 'theme.auth_overlay_color' => is_string($value) && $value !== '' ? strtoupper($value) : $value,
            default => $value,
        };
    }

    /**
     * Contrast warnings (PLATFORM_CONSOLE.md § 9.2, § 9.8): white text on
     * the primary colour, and white text over a photo and its overlay.
     */
    protected function afterSave(array $changes): void
    {
        $theme = $this->manager()->draftTheme();
        $primary = $theme->primary();
        $onPrimary = Color::contrast('#ffffff', $primary);

        if ($onPrimary < 4.5) {
            $darker = $theme->primaryPalette()[700];
            Notification::make()
                ->title('White text on the primary colour is hard to read')
                ->body(sprintf('%s gives %.1f:1, below 4.5:1. Buttons and links use the darker shade %s (%.1f:1).', strtoupper($primary), $onPrimary, $darker, Color::contrast('#ffffff', $darker)))
                ->warning()
                ->persistent()
                ->send();
        }

        $assets = app(BrandAssets::class);
        $deep = Palette::heroDeep($primary)[0];

        foreach (['hero' => ['theme.hero_overlay_color', 'theme.hero_overlay_opacity', 'landing hero'], 'auth' => ['theme.auth_overlay_color', 'theme.auth_overlay_opacity', 'sign-in panel']] as $role => [$colourKey, $opacityKey, $place]) {
            $path = $assets->path($theme, $role);

            if ($path === null) {
                continue;
            }

            $overlay = $theme->string($colourKey, $deep);
            $opacity = $theme->get($opacityKey);
            $contrast = $assets->overlayContrast($path, $overlay, is_int($opacity) ? $opacity : 60);

            if ($contrast !== null && $contrast < 4.5) {
                Notification::make()
                    ->title("The {$place} headline may be hard to read")
                    ->body(sprintf('White text over this photo and overlay averages %.1f:1, below 4.5:1. Raise the overlay strength or pick a darker photo.', $contrast))
                    ->warning()
                    ->persistent()
                    ->send();
            }
        }
    }
}
