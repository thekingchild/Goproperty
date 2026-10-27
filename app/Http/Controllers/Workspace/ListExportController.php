<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Exports\ListExportRequests;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /o/{organization}/exports/{ticket}: the file a list's Export button asked
 * for (App\Exports\ListExportRequests::download): the ticket is the
 * member's own, for this organization, used once, within five minutes; the
 * list's policy is checked again, the export audited, and the file
 * streamed. A GET, so it also works in a suspended organization and in a
 * read-only support session (the list's policy decides about staff).
 */
final class ListExportController
{
    public function __invoke(Request $request, Organization $organization, string $ticket, ListExportRequests $exports): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $exports->download($user, $ticket);
    }
}
