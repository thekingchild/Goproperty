<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Marketing\MarketingController;
use App\Theme\ThemeContext;
use App\Theme\ThemeManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a surface with the draft theme for signed-in staff
 * (PLATFORM_CONSOLE.md § 9.8). Only this console route switches the theme
 * context to the draft, so a draft never reaches the public site.
 */
final class ThemePreviewController
{
    public const array SURFACES = ['app', 'landing'];

    public function __invoke(string $surface, ThemeManager $themes, ThemeContext $context, Container $container): Response
    {
        $context->preview($themes->draftTheme());

        if ($surface === 'landing') {
            // The real landing page; its layout shows the preview banner and
            // leaves out custom code while the context is a preview.
            View::share('themePreviewDraft', $themes->hasDraft());
            $view = $container->call([$container->make(MarketingController::class), 'home']);
        } else {
            $view = view('theme-preview.app', ['draft' => $themes->hasDraft()]);
        }

        return response($view)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
