<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Portal\PortalDocuments as Documents;

/**
 * My documents, /portal/documents (P03-11b; BRANDING_DOCUMENTS.md § 5): per
 * agency, the tenancy agreements (lease contracts), invoices and receipts
 * (with the VOID copy of a voided one), each a download of the file kept at
 * issue through a short-lived signed link, after the portal's policy check
 * (Http\Controllers\PortalDocumentController). While GoProperty staff
 * impersonate the user the list shows but nothing opens. Read-only.
 */
#[Layout('layouts::app')]
#[Title('My documents')]
final class PortalDocuments extends Component
{
    public function render(Documents $documents): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('tenants::livewire.portal.documents', ['agencies' => $documents->of($user)]);
    }
}
