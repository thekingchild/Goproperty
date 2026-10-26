<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Theme\BrandAssets;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Search & sharing (PLATFORM_CONSOLE.md § 9.4). The preview
 * column shows the Google result and the link card from the draft.
 * Changing the canonical origin needs step-up.
 */
final class SearchSharing extends DraftSettingsPage
{
    protected static ?string $slug = 'settings/seo';

    protected static ?string $title = 'Search & sharing';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    public static function permission(): string
    {
        return 'settings.seo';
    }

    public static function settingKeys(): array
    {
        return ['seo.title', 'seo.title_pattern', 'seo.description', 'seo.share_image', 'seo.indexing_enabled', 'seo.canonical_origin'];
    }

    protected function fields(): array
    {
        $plain = 'regex:/^[^<>]*$/';

        return [
            Section::make('Search results')
                ->schema([
                    TextInput::make('seo.title')->label('Home page title')->maxLength(70)->rule($plain)->live(onBlur: true)
                        ->helperText('Leave empty for "product name — tagline".'),
                    TextInput::make('seo.title_pattern')->label('Title pattern for other pages')->required()->maxLength(70)->rule($plain)->rule('regex:/\{page\}/')
                        ->helperText('{page} is the page name, {site} the product name.'),
                    Textarea::make('seo.description')->label('Description')->maxLength(320)->rows(3)->rule($plain)->live(onBlur: true)
                        ->helperText('About 160 characters show in search results.'),
                ]),
            Section::make('Link previews')
                ->schema([
                    $this->imageUpload('share-image', 'Share image', 'JPG, PNG or WebP, up to 3 MB, 200 to 4096 px a side; 1200 × 630 is ideal. Converted to JPEG with photo details removed.'),
                ]),
            Section::make('Indexing and address')
                ->columns(2)
                ->schema([
                    Select::make('seo.indexing_enabled')->label('Search engines')->required()
                        ->options(['auto' => 'Automatic: production only', 'on' => 'Allow indexing', 'off' => 'Keep out of search engines'])
                        ->helperText('Off: robots.txt disallows everything and pages say noindex.'),
                    TextInput::make('seo.canonical_origin')->label('Canonical address')->placeholder(rtrim((string) config('app.url'), '/'))
                        ->rule('regex:/^https:\/\/[a-z0-9.\-]+(:\d{1,5})?$/')
                        ->helperText('https://host only. Changing it asks for your password.'),
                ]),
        ];
    }

    protected function preview(): Component
    {
        return View::make('console.settings.search-preview')->viewData(function (): array {
            $theme = $this->manager()->draftTheme();
            $title = trim((string) data_get($this->data, 'seo.title'));
            $description = trim((string) data_get($this->data, 'seo.description'));
            $origin = trim((string) data_get($this->data, 'seo.canonical_origin')) ?: rtrim((string) config('app.url'), '/');

            return [
                'title' => $title !== '' ? $title : $theme->name().' — '.$theme->string('theme.tagline'),
                'description' => $description,
                'host' => (string) parse_url($origin, PHP_URL_HOST),
                'name' => $theme->name(),
                'image' => app(BrandAssets::class)->url($theme, 'share-image'),
            ];
        });
    }

    protected function toFormValue(string $key, mixed $value): mixed
    {
        return $key === 'seo.indexing_enabled' ? match ($value) {
            true => 'on',
            false => 'off',
            default => 'auto',
        } : $value;
    }

    protected function fromFormValue(string $key, mixed $value): mixed
    {
        return $key === 'seo.indexing_enabled' ? match ($value) {
            'on' => true,
            'off' => false,
            default => null,
        } : $value;
    }
}
