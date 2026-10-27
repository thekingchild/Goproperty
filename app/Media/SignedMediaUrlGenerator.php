<?php

declare(strict_types=1);

namespace App\Media;

use DateTimeInterface;
use LogicException;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * Makes the media library's getUrl() / getTemporaryUrl() return the signed
 * /media route (MediaUrls) instead of a disk or bucket URL, so no code can
 * hand out a link that skips it. Paths on the disk (getPath()) are
 * unchanged.
 */
final class SignedMediaUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        return app(MediaUrls::class)->temporary($this->ownMedia(), $this->conversion?->getName() ?? '');
    }

    /** @param  array<string, mixed>  $options */
    public function getTemporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        return app(MediaUrls::class)->temporary($this->ownMedia(), $this->conversion?->getName() ?? '', $expiration);
    }

    public function getResponsiveImagesDirectoryUrl(): string
    {
        throw new LogicException('Responsive images are not served; use the conversions of config/media.php.');
    }

    private function ownMedia(): Media
    {
        return $this->media instanceof Media
            ? $this->media
            : throw new LogicException('config/media-library.php must use '.Media::class.' as the media model.');
    }
}
