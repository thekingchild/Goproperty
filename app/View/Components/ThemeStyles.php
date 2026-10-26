<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Theme\ThemeContext;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Throwable;

/**
 * Prints the current theme in <head> (PLATFORM_CONSOLE.md § 9.8): the chosen
 * font (preloads and @font-face for that family only), the theme colour and
 * every token as an unlayered :root rule, so it overrides the build's
 * defaults without an asset rebuild. All values are generated hex, numbers
 * or names from fixed lists, never free text.
 */
final class ThemeStyles extends Component
{
    public function __construct(
        private readonly ThemeContext $themes,
        private readonly Vite $vite,
    ) {}

    public function render(): HtmlString
    {
        $theme = $this->themes->current();

        try {
            $fonts = (string) $this->vite->fonts($theme->fontAlias());
        } catch (Throwable) {
            // No font manifest (assets not built): the system font is used.
            $fonts = '';
        }

        return new HtmlString(
            $fonts
            ."\n<meta name=\"theme-color\" content=\"{$theme->themeColor()}\">"
            ."\n<style data-theme>{$theme->css()}</style>"
        );
    }
}
