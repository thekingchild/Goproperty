<?php

declare(strict_types=1);

namespace App\Media;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * For a model that owns files (`implements HasMedia`): the media library's
 * relation, plus the conversions of config/media.php for the collections
 * that ask for them. Conversions are WebP made with GD, never upscaled, not
 * run through optimizer binaries, and queued on the `media` queue
 * (ARCHITECTURE.md § 9, § 12.1). Add files with MediaUploads, not
 * addMedia() directly, so they are checked first.
 *
 * @phpstan-require-implements HasMedia
 */
trait HasOrganizationMedia
{
    use InteractsWithMedia;

    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        $conversions = config('media.conversions');

        foreach (is_array($conversions) ? $conversions : [] as $name => $spec) {
            $collections = is_string($name) && is_array($spec) ? CollectionRules::collectionsWithConversion($name) : [];

            if ($collections === []) {
                continue;
            }

            // The conversion's own settings first: the image manipulations
            // after them are forwarded to spatie/image.
            $this->addMediaConversion($name)
                ->nonOptimized()
                ->queued()
                ->performOnCollections(...$collections)
                ->fit(Fit::Max, self::dimension($spec, 'width'), self::dimension($spec, 'height'))
                ->format('webp')
                ->quality(self::dimension($spec, 'quality', 80));
        }
    }

    /** @param  array<mixed>  $spec */
    private static function dimension(array $spec, string $key, int $default = 400): int
    {
        $value = $spec[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
