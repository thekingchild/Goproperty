<?php

declare(strict_types=1);

namespace Modules\Tickets\Messages;

/**
 * A file kept with a message (TicketThread): its media id (the file
 * routes take it), the uploader's file name as a label, the stored type,
 * its size, and whether pages show it as a picture (a JPEG, PNG or WebP,
 * or any image whose thumbnail was made; a HEIC photo without one is
 * offered as a file).
 */
final readonly class ThreadAttachment
{
    public function __construct(
        public int $mediaId,
        public string $name,
        public string $mimeType,
        public int $size,
        public bool $picture,
    ) {}

    /** "PDF · 240 KB" */
    public function caption(): string
    {
        $type = match ($this->mimeType) {
            'application/pdf' => 'PDF',
            'image/heic', 'image/heif' => 'HEIC',
            'image/png' => 'PNG',
            'image/webp' => 'WebP',
            default => 'JPEG',
        };
        $size = $this->size >= 1024 * 1024 ? number_format($this->size / (1024 * 1024), 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';

        return $type.' · '.$size;
    }
}
