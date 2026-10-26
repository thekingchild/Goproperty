<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Theme\BrandAssets;
use App\Theme\ThemeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the platform's brand images at /brand/{role} (PLATFORM_CONSOLE.md
 * § 9.2). With ?v={hash} the file is immutable and cached for a year;
 * without it the published file is served briefly cached. Logos, the icon
 * and the favicon fall back to the generated mark in theme colours.
 */
final class BrandAssetController
{
    public function __invoke(string $role, Request $request, BrandAssets $assets, ThemeContext $themes): Response
    {
        if ($role === 'apple-touch-icon') {
            return $this->touchIcon($assets, $themes);
        }

        $version = $request->query('v');
        $width = $request->integer('w') ?: null;
        $path = $assets->locate($role, is_string($version) ? $version : null, $width, $themes->current());

        if ($path === null) {
            return $this->generated($role, $themes);
        }

        $type = $assets->mimeType($path);
        $headers = [
            'Content-Type' => $type,
            'Cache-Control' => $version !== null ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($type === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return response((string) $assets->disk()->get($path), 200, $headers);
    }

    private function touchIcon(BrandAssets $assets, ThemeContext $themes): Response
    {
        $path = $assets->touchIcon($themes->current());
        $png = $path !== null ? (string) $assets->disk()->get($path) : $assets->generatedTouchIcon($themes->current());

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function generated(string $role, ThemeContext $themes): Response
    {
        $tone = match ($role) {
            'logo-dark' => 'light',
            'logo-light', 'icon', 'favicon' => 'brand',
            default => abort(404),
        };

        $theme = $themes->current();
        $palette = $theme->primaryPalette();
        // Standalone file: colours inlined, no CSS variables.
        $svg = Blade::render(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 34 34"><rect width="34" height="34" rx="9" fill="{{ $ground }}"/><path d="M9 16.2 17 10l8 6.2V25a1 1 0 0 1-1 1h-4.5v-5.5h-5V26H10a1 1 0 0 1-1-1z" fill="{{ $house }}"/><circle cx="24.5" cy="10.5" r="3" fill="{{ $accent }}"/></svg>',
            [
                'ground' => $tone === 'light' ? '#ffffff' : $palette[700],
                'house' => $tone === 'light' ? $palette[700] : '#ffffff',
                'accent' => $theme->accent(),
            ],
        );

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
