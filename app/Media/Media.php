<?php

declare(strict_types=1);

namespace App\Media;

use App\Tenancy\BelongsToOrganization;
use Closure;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * A stored file (ARCHITECTURE.md § 9): the media library's record, owned by
 * one organization like every customer record (§ 4). Queries are limited
 * to the current organization and fail without one; a file can never be
 * attached in, or moved to, another organization. Added only through
 * MediaUploads (which checks the file first), served only through the
 * signed route (MediaUrls, MediaController).
 *
 * `name` is the uploader's file name without its extension (shown, and
 * used for downloads); `file_name` is random with the extension of the
 * sniffed type; `custom_properties` holds the sha256 of the original and,
 * for images, its width and height.
 *
 * Files of an evidence collection (config/media.php `kind`) cannot be
 * deleted (AGENT.md § 3 rule 7): deleting one throws, unless inside
 * purging(), which only the NDPA erasure tooling may call.
 *
 * @property int $id
 * @property string $uuid
 * @property string $model_type
 * @property int $model_id
 * @property string $collection_name
 * @property string $name
 * @property string $file_name
 * @property string|null $mime_type
 * @property string $disk
 * @property string|null $conversions_disk
 * @property int $size
 */
class Media extends SpatieMedia
{
    use BelongsToOrganization;

    protected $table = 'media';

    private static bool $purging = false;

    protected static function booted(): void
    {
        static::deleting(static function (self $media): void {
            if (! self::$purging && CollectionRules::isEvidence($media->collection_name)) {
                throw new EvidenceFileProtected("Files in the \"{$media->collection_name}\" collection are evidence and are never deleted; archive the record instead.");
            }
        });
    }

    /**
     * Runs $callback with evidence deletion allowed: for the NDPA erasure
     * tooling only (ARCHITECTURE.md § 9, AGENT.md § 3 rule 7).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function purging(Closure $callback): mixed
    {
        $previous = self::$purging;
        self::$purging = true;

        try {
            return $callback();
        } finally {
            self::$purging = $previous;
        }
    }

    /** The sha256 of the stored original, taken when it was added. */
    public function checksum(): ?string
    {
        $value = $this->getCustomProperty('sha256');

        return is_string($value) ? $value : null;
    }
}
