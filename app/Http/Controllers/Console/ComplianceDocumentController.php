<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Compliance\ComplianceAudit;
use App\Compliance\ComplianceFiles;
use App\Models\PlatformStaff;
use App\Platform\Compliance\ReviewDocument;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Opens a compliance document's file for staff (ONBOARDING_COMPLIANCE.md
 * § 5.3, ARCHITECTURE.md § 9): a console route behind the staff guard and
 * 2FA, reached only through a signed link that expires in minutes
 * (DocumentLinks), for staff holding `compliance.view_documents`. The file
 * is decrypted here, checked against the checksum taken at upload, and
 * every view is written to the audit log with the staff member. The
 * response is never cached and never sniffed as another type.
 */
final class ComplianceDocumentController
{
    public function __invoke(Request $request, int $document, ComplianceFiles $files, ComplianceAudit $audit): Response
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof PlatformStaff, 403);

        $record = ReviewDocument::query()->withTrashed()->find($document);
        abort_if($record === null, 404);
        Gate::forUser($staff)->authorize('viewFile', $record);

        $bytes = $files->read($record->disk, $record->path);

        if (! hash_equals($record->sha256, hash('sha256', $bytes))) {
            Log::critical('A compliance document does not match its checksum.', ['document' => $record->id]);

            throw new HttpException(409, 'This document file does not match what was uploaded.');
        }

        $audit->record('compliance_document.viewed', $record, $staff, "Opened the {$record->type->label()} of organization #{$record->organization_id}", [
            'organization_id' => $record->organization_id,
            'tier_application_id' => $record->tier_application_id,
            'type' => $record->type->value,
        ]);

        $name = str_replace(['"', '\\'], '', $record->original_name);

        return response($bytes, 200, [
            'Content-Type' => $record->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
