<?php

declare(strict_types=1);

namespace App\Media;

use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

/**
 * Makes a file's conversions (thumbnails, WebP) on the `media` queue, inside
 * the file's organization. One job per file, all its conversions in one go:
 * a 25-megapixel photo takes a few seconds with GD, well inside the 50 s a
 * shared host's queue tick gives a job (AGENT.md § 4, config/hosting.php).
 * Safe to run again: it rewrites the same files.
 */
final class PerformMediaConversions extends PerformConversionsJob
{
    use RunsInMediaOrganization;

    /** Seconds; the queue tick's budget per job (QUEUE_TICK_JOB_TIMEOUT). */
    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(FileManipulator $fileManipulator): bool
    {
        return $this->inMediaOrganization($this->media, fn (): bool => parent::handle($fileManipulator));
    }
}
