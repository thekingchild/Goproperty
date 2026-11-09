<?php

declare(strict_types=1);

namespace App\Media;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Moves the media library's files between the host's disk (`media`) and the
 * bucket (`media-s3`), row by row (`goproperty:media-move`, product owner
 * 2026-11-09: the host's disk is the default and a later move must be
 * supported). Each file lives in its own folder, `{organization id}/{uuid}/`
 * (MediaPathGenerator), with its conversions and responsive images below it,
 * and each row names its disk, which is what MediaController serves from.
 * So a row's folder is copied, every copied file's size checked, and only
 * then is the row pointed at the new disk; until then it is served from the
 * old one. The old copy is kept unless asked otherwise.
 *
 * Works on the `media` table directly: the model's queries need one
 * organization, and this runs across all of them. Rows changed in the last
 * few minutes are left for the next run (their conversions may still be
 * being made on the old disk), so the move is repeated until none is left.
 */
final class MediaDiskMove
{
    public const array DISKS = ['media', 'media-s3'];

    /** Rows changed more recently than this are left for the next run. */
    public const int SETTLE_MINUTES = 10;

    private const string PROBE = '.goproperty-move-check';

    /**
     * Writes, reads back and deletes a small file on the target disk.
     *
     * @throws RuntimeException with a message for the person running it
     */
    public function checkTarget(string $disk): void
    {
        self::assertDisk($disk);

        if ($disk === 'media-s3' && trim((string) config('filesystems.disks.media-s3.bucket')) === '') {
            throw new RuntimeException('No bucket is set: put the bucket\'s settings (AWS_* or MEDIA_S3_*) in .env first.');
        }

        $storage = Storage::disk($disk);
        $marker = bin2hex(random_bytes(8));

        try {
            $storage->put(self::PROBE, $marker);
            $read = $storage->get(self::PROBE);
            $storage->delete(self::PROBE);
        } catch (Throwable $exception) {
            throw new RuntimeException("The {$disk} disk cannot be written to: ".$exception->getMessage(), 0, $exception);
        }

        if ($read !== $marker) {
            throw new RuntimeException("The file read back from the {$disk} disk was not the one written.");
        }
    }

    /**
     * @param  Closure(string): void|null  $progress  told about each failure
     * @return array{moved: int, bytes: int, waiting: int, missing: int, failed: int, cleaned: int}
     */
    public function move(string $to, bool $deleteSource = false, ?Closure $progress = null, ?CarbonImmutable $now = null): array
    {
        self::assertDisk($to);
        $from = $to === 'media' ? 'media-s3' : 'media';
        $settled = ($now ?? CarbonImmutable::now())->subMinutes(self::SETTLE_MINUTES);
        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $result = ['moved' => 0, 'bytes' => 0, 'waiting' => 0, 'missing' => 0, 'failed' => 0, 'cleaned' => 0];

        $result['waiting'] = $this->onDisk($from)->where('updated_at', '>', $settled)->count();

        $this->onDisk($from)
            ->where(fn ($query) => $query->whereNull('updated_at')->orWhere('updated_at', '<=', $settled))
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($from, $to, $source, $target, $deleteSource, $progress, &$result): void {
                foreach ($rows as $row) {
                    $folder = (int) $row->organization_id.'/'.$row->uuid;

                    if (! $source->exists($folder.'/'.$row->file_name)) {
                        $result['missing']++;
                        $progress?->__invoke("File {$row->id} is missing on the {$from} disk; left as it is.");

                        continue;
                    }

                    try {
                        $this->copyFolder($source, $target, $folder);
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        $progress?->__invoke("File {$row->id} could not be copied: ".$exception->getMessage());

                        continue;
                    }

                    $updated = DB::table('media')->where('id', $row->id)->where('disk', $from)->update(array_filter([
                        'disk' => $to,
                        'conversions_disk' => in_array($row->conversions_disk, [null, $from], true) ? $to : null,
                    ]));

                    if ($updated === 1) {
                        $result['moved']++;
                        $result['bytes'] += (int) $row->size;

                        if ($deleteSource) {
                            $source->deleteDirectory($folder);
                        }
                    }
                }
            });

        if ($deleteSource) {
            $result['cleaned'] = $this->cleanMoved($from, $to, $source, $target);
        }

        return $result;
    }

    /**
     * How many files each disk holds, for the summary.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (DB::table('media')->groupBy('disk')->orderBy('disk')->select('disk')->selectRaw('COUNT(*) AS files')->get() as $row) {
            $counts[(string) $row->disk] = (int) $row->files;
        }

        return $counts;
    }

    /**
     * Copies every file of a folder and checks each copy's size. Throws on
     * the first file that does not match; the row then stays where it is.
     */
    private function copyFolder(Filesystem $source, Filesystem $target, string $folder): void
    {
        foreach ($source->allFiles($folder) as $path) {
            $stream = $source->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException("{$path} could not be read.");
            }

            // The S3 client may close the stream itself; otherwise PHP closes
            // it when $stream is replaced on the next file.
            $target->writeStream($path, $stream);
            unset($stream);

            if ($target->size($path) !== $source->size($path)) {
                throw new RuntimeException("the copy of {$path} has a different size.");
            }
        }
    }

    /**
     * With --delete-source on a later run: removes old folders of rows moved
     * before, once their original is on the target with the same size.
     */
    private function cleanMoved(string $from, string $to, Filesystem $source, Filesystem $target): int
    {
        $cleaned = 0;

        DB::table('media')->where('disk', $to)->orderBy('id')->select(['id', 'organization_id', 'uuid', 'file_name'])
            ->chunkById(200, function ($rows) use ($source, $target, &$cleaned): void {
                foreach ($rows as $row) {
                    $folder = (int) $row->organization_id.'/'.$row->uuid;
                    $original = $folder.'/'.$row->file_name;

                    if ($source->exists($original) && $target->exists($original) && $target->size($original) === $source->size($original)) {
                        $source->deleteDirectory($folder);
                        $cleaned++;
                    }
                }
            });

        return $cleaned;
    }

    /** @return Builder */
    private function onDisk(string $disk)
    {
        return DB::table('media')->where('disk', $disk)
            ->select(['id', 'organization_id', 'uuid', 'file_name', 'conversions_disk', 'size']);
    }

    private static function assertDisk(string $disk): void
    {
        if (! in_array($disk, self::DISKS, true)) {
            throw new RuntimeException('Move to media-s3 (the bucket) or media (this host), not '.$disk.'.');
        }
    }
}
