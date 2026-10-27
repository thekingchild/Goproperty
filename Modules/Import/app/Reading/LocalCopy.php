<?php

declare(strict_types=1);

namespace Modules\Import\Reading;

use App\Media\Media;
use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The spreadsheet readers need a local file; the upload may be on the
 * media bucket. Copies the file to a private temporary file for the length
 * of the callback, then deletes it.
 */
final class LocalCopy
{
    /**
     * @template T
     *
     * @param  Closure(string): T  $callback  receives the local path
     * @return T
     */
    public static function using(Media $media, Closure $callback): mixed
    {
        $stream = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot());

        if (! is_resource($stream)) {
            throw new RuntimeException('The uploaded file is missing from the media disk.');
        }

        $path = tempnam(sys_get_temp_dir(), 'gp-import-');

        if ($path === false) {
            throw new RuntimeException('No temporary file could be made for the import.');
        }

        try {
            $local = fopen($path, 'wb');

            if ($local === false) {
                throw new RuntimeException('No temporary file could be made for the import.');
            }

            stream_copy_to_stream($stream, $local);
            fclose($local);

            return $callback($path);
        } finally {
            fclose($stream);

            @unlink($path);
        }
    }
}
