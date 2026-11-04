<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Models\DataRequest;
use App\Models\PlatformStaff;
use App\Privacy\Export\ExportDownload;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A data export's archive for staff (P09-02; PLATFORM_CONSOLE.md § 11): a
 * console route behind the staff guard and 2FA, reached only through a
 * signed link that lives 15 minutes (DataRequests::downloadUrl()), for
 * staff holding `privacy.requests` (DataRequestPolicy::download). Audited.
 */
final class DataExportDownloadController
{
    public function __invoke(int $dataRequest, ExportDownload $download): StreamedResponse
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof PlatformStaff, 403);

        $request = DataRequest::query()->findOrFail($dataRequest);
        abort_unless(Gate::forUser($staff)->allows('download', $request), 403);

        return $download->response($request, $staff);
    }
}
