<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Media\Media;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * /media/{organization}/{media}/{conversion?}: streams a stored file from
 * the private media disk (ARCHITECTURE.md § 9–10).
 *
 * The signature is the permission: links are made by MediaUrls only for
 * people the owning record's policy lets see the file, and expire after
 * minutes. A missing, tampered or expired signature gets the same 404 as
 * an unknown file, so the route reveals nothing.
 *
 * The organization comes from the signed URL and becomes the context; the
 * file is then looked up by its unguessable uuid through the normal
 * organization scope (the pattern of P01-04's invitation links), so a URL
 * signed for one organization can never reach another's file and no
 * scope is removed.
 *
 * No session or cookies (routes/web.php), so a page of thumbnails costs no
 * session writes. Responses are private, never sniffed as another type,
 * and sandboxed; images are shown inline, other files download.
 */
final class MediaController
{
    /** Types of the conversions (all WebP today), by file extension. */
    private const array CONVERSION_TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public function __invoke(Request $request, OrganizationContext $context, string $organization, string $media, ?string $conversion = null): Response
    {
        abort_unless(URL::hasValidSignature($request), 404);

        $owner = Organization::query()->find((int) $organization);
        abort_if($owner === null, 404);

        return $context->run($owner, function () use ($request, $media, $conversion): Response {
            $record = Media::query()->where('uuid', $media)->first();
            abort_if($record === null, 404);

            return $this->stream($request, $record, $conversion ?? '');
        });
    }

    private function stream(Request $request, Media $media, string $conversion): StreamedResponse
    {
        if ($conversion !== '' && ! $media->hasGeneratedConversion($conversion)) {
            abort(404);
        }

        $diskName = $conversion !== '' ? ($media->conversions_disk ?? $media->disk) : $media->disk;
        $disk = Storage::disk($diskName);
        $path = $media->getPathRelativeToRoot($conversion);

        try {
            $stream = $disk->readStream($path);
            $size = $conversion !== '' ? $disk->size($path) : $media->size;
        } catch (Throwable $exception) {
            $stream = null;
            $size = 0;
        }

        if (! is_resource($stream)) {
            Log::warning('A media file is missing from its disk.', ['media' => $media->id, 'conversion' => $conversion]);
            abort(404);
        }

        $type = $conversion !== ''
            ? (self::CONVERSION_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream')
            : ($media->mime_type ?? 'application/octet-stream');
        $inline = str_starts_with($type, 'image/');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $filename = str_replace(['"', '\\', '/'], '', Str::ascii($media->name)).($extension !== '' ? '.'.$extension : '');
        $expires = (int) $request->query('expires', '0');

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) $size,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.($filename !== '' ? $filename : 'file').'"',
            // Never longer than the link lives, never in a shared cache.
            'Cache-Control' => 'private, max-age='.max(0, $expires - now()->getTimestamp()),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
