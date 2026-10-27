<?php

declare(strict_types=1);

namespace App\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Where a file lives on the media disk: `{organization id}/{media uuid}/`,
 * conversions in `conversions/` below it. The uuid makes paths
 * unguessable (Spatie's default uses the auto-increment id), and one
 * organization's files stay under one prefix (exports, erasure).
 */
final class MediaPathGenerator implements PathGenerator
{
    public function getPath(SpatieMedia $media): string
    {
        return $this->base($media).'/';
    }

    public function getPathForConversions(SpatieMedia $media): string
    {
        return $this->base($media).'/conversions/';
    }

    public function getPathForResponsiveImages(SpatieMedia $media): string
    {
        return $this->base($media).'/responsive-images/';
    }

    private function base(SpatieMedia $media): string
    {
        return (int) $media->getAttribute('organization_id').'/'.$media->getAttribute('uuid');
    }
}
