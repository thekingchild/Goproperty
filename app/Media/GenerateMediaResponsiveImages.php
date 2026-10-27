<?php

declare(strict_types=1);

namespace App\Media;

use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;

/**
 * Spatie's responsive-images job, inside the file's organization. GoProperty
 * does not use responsive images (the conversions of config/media.php
 * cover it); this only keeps the job from failing on the scoped model if
 * a collection ever turns them on.
 */
final class GenerateMediaResponsiveImages extends GenerateResponsiveImagesJob
{
    use RunsInMediaOrganization;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(): bool
    {
        return $this->inMediaOrganization($this->media, fn (): bool => parent::handle());
    }
}
