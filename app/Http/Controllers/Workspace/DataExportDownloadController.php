<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Models\DataRequest;
use App\Models\Organization;
use App\Models\User;
use App\Privacy\Export\ExportDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A data export's archive for the organization's owner (P09-02; Settings →
 * Data export): a signed link that lives 15 minutes, for members who may
 * export the organization's data (OrganizationPolicy::exportData: owners,
 * never during a GoProperty support session), and only an export of this
 * organization. Audited.
 */
final class DataExportDownloadController
{
    public function __invoke(Request $request, Organization $organization, int $dataRequest, ExportDownload $download): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('exportData', $organization), 403);

        $export = DataRequest::query()->where('organization_id', $organization->id)->findOrFail($dataRequest);

        return $download->response($export, $user);
    }
}
