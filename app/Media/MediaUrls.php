<?php

declare(strict_types=1);

namespace App\Media;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Links to stored files (ARCHITECTURE.md § 9: "access via short-lived
 * signed URLs only"): /media/{organization}/{media uuid}/{conversion?},
 * signed with the app key and expiring after `media.url_lifetime_minutes`.
 * The link itself is the permission to see the file until it expires, so
 * build one only for someone the owning record's policy lets see it.
 *
 * `$media->getUrl()` gives the same link (SignedMediaUrlGenerator).
 */
final class MediaUrls
{
    public function temporary(Media $media, string $conversion = '', ?DateTimeInterface $expires = null): string
    {
        $parameters = ['organization' => $media->organization_id, 'media' => $media->uuid];

        if ($conversion !== '') {
            $parameters['conversion'] = $conversion;
        }

        return URL::temporarySignedRoute('media.show', $expires ?? now()->addMinutes(self::lifetimeMinutes()), $parameters);
    }

    /**
     * The first of these conversions that has been made, else the original:
     * a new photo shows at once and its thumbnail once the queue made it.
     */
    public function preview(Media $media, string ...$conversions): string
    {
        foreach ($conversions as $conversion) {
            if ($media->hasGeneratedConversion($conversion)) {
                return $this->temporary($media, $conversion);
            }
        }

        return $this->temporary($media);
    }

    public static function lifetimeMinutes(): int
    {
        $minutes = config('media.url_lifetime_minutes');

        return is_int($minutes) && $minutes > 0 ? $minutes : 15;
    }
}
