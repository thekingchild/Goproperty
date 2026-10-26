<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * The theme this request renders with: the published one, or the draft
 * when a staff member opens the console preview. Scoped, so it starts on
 * the published theme for every request; nothing outside the preview can
 * switch to the draft.
 */
final class ThemeContext
{
    private ?Theme $preview = null;

    private ?Theme $published = null;

    public function __construct(private readonly ThemeManager $manager) {}

    public function current(): Theme
    {
        return $this->preview ?? ($this->published ??= $this->manager->published());
    }

    public function preview(Theme $theme): void
    {
        $this->preview = $theme;
    }

    public function isPreview(): bool
    {
        return $this->preview !== null;
    }
}
