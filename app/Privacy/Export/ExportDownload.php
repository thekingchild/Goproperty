<?php

declare(strict_types=1);

namespace App\Privacy\Export;

use App\Models\DataRequest;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Privacy\DataRequests;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an export's archive (P09-02), for the console's and the
 * workspace's signed download routes once they have checked who asks:
 * audited, never cached, saved as a file.
 */
final readonly class ExportDownload
{
    public function __construct(private DataRequests $requests) {}

    public function response(DataRequest $request, PlatformStaff|User $by): StreamedResponse
    {
        abort_unless($request->isDownloadable(), 404);

        $disk = Storage::disk(DataExportBuilder::DISK);
        $stream = $disk->exists((string) $request->file_path) ? $disk->readStream((string) $request->file_path) : null;
        abort_if($stream === null, 404);

        $this->requests->recordDownload($request, $by);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, "data-export-{$request->id}.zip", [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
