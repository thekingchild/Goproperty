<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use Illuminate\Support\Facades\URL;

/**
 * Links to a compliance document's file for staff: signed and valid for a
 * few minutes (config `compliance.signed_url_minutes`). The route also
 * checks the staff member's `compliance.view_documents` and audits every
 * view (ComplianceDocumentController), so a forwarded link is useless to
 * anyone else and soon to its owner too (ARCHITECTURE.md § 9).
 */
final class DocumentLinks
{
    public const string ROUTE = 'filament.console.compliance-document';

    public function for(ReviewDocument $document): string
    {
        $minutes = config('compliance.signed_url_minutes', 5);

        return URL::temporarySignedRoute(self::ROUTE, now()->addMinutes(is_numeric($minutes) ? (int) $minutes : 5), ['document' => $document->id]);
    }
}
