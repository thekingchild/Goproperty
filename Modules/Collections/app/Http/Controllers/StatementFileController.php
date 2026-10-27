<?php

declare(strict_types=1);

namespace Modules\Collections\Http\Controllers;

use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Collections\Models\BankStatementImport;

/**
 * The uploaded statement file, /o/{organization}/statements/{statement}/file:
 * after BankStatementImportPolicy::downloadFile (members who see
 * statements; never impersonating staff), a short-lived signed link
 * (App\Media\MediaUrls) to the kept file. {statement} resolves inside the
 * organization only, so another organization's statement answers 404.
 */
final class StatementFileController
{
    public function __invoke(Organization $organization, BankStatementImport $statement, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('downloadFile', $statement);
        $file = $statement->file();
        abort_if($file === null, 404);

        return redirect()->away($urls->temporary($file));
    }
}
