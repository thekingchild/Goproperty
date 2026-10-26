<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Theme\ThemeContext;
use App\Theme\ThemeManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a surface with the draft theme for signed-in staff
 * (PLATFORM_CONSOLE.md § 9.8). Only this console route switches the theme
 * context to the draft, so a draft never reaches the public site.
 */
final class ThemePreviewController
{
    public const array SURFACES = ['app'];

    public function __invoke(string $surface, ThemeManager $themes, ThemeContext $context): Response
    {
        $context->preview($themes->draftTheme());

        return response()
            ->view('theme-preview.'.$surface, ['draft' => $themes->hasDraft()])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
