<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Http\Controllers;

use App\Media\MediaUrls;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\OwnerStatements\Documents\StatementPdfs;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\StatementMonth;
use Modules\Properties\Contracts\OwnerPortalAccess;
use Modules\Properties\Contracts\PortalOwner;
use Symfony\Component\HttpFoundation\Response;

/**
 * A month's owner statement PDF (P08-05): a settled month's kept PDF
 * through a short-lived signed link, a month not settled rendered now as
 * a download. For the agency (OwnerStatementPolicy::downloadOwner) and for
 * the owner in the owner portal (OwnerPortalAccess: the owner's own
 * profile only, else 404). Figures and tenants' names only, no contact
 * details, so not blocked while GoProperty staff impersonate.
 */
final class OwnerStatementPdfController
{
    public function workspace(Organization $organization, int $owner, string $month, OwnerStatements $statements, StatementPdfs $pdfs, MediaUrls $urls): Response
    {
        $month = StatementMonth::fromKey($month) ?? abort(404);
        Gate::authorize('downloadOwner', [OwnerStatementSettlement::class, $owner]);

        return $this->send($statements, $pdfs, $urls, $owner, $month);
    }

    public function portal(Request $request, int $owner, string $month, OwnerPortalAccess $portal, OwnerStatements $statements, StatementPdfs $pdfs, MediaUrls $urls): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $month = StatementMonth::fromKey($month) ?? abort(404);

        return $portal->within($user, $owner, fn (PortalOwner $found): Response => $this->send($statements, $pdfs, $urls, $found->ownerProfileId, $month)) ?? abort(404);
    }

    private function send(OwnerStatements $statements, StatementPdfs $pdfs, MediaUrls $urls, int $owner, StatementMonth $month): Response
    {
        $statement = $statements->month($owner, $month);
        $kept = $pdfs->kept($statement);

        if ($kept !== null) {
            return redirect()->away($urls->temporary($kept))->header('Cache-Control', 'no-store, private');
        }

        $document = $pdfs->document($statement);

        return response($pdfs->rendered($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->fileName().'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
