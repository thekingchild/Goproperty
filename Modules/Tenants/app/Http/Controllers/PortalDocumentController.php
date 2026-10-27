<?php

declare(strict_types=1);

namespace Modules\Tenants\Http\Controllers;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Tenants\Portal\PortalDocuments;

/**
 * The tenant's documents and files from the portal (P03-11b;
 * BRANDING_DOCUMENTS.md § 5: "every document is also viewable as a
 * responsive web page; the PDF is a download"; ARCHITECTURE.md § 9: files
 * only through short-lived signed links):
 *
 * - /portal/bills/{bill}/invoice.pdf: the invoice as kept (the VOID copy
 *   once the bill is void);
 * - /portal/leases/{lease}/contract: the lease contract PDF as kept;
 * - /portal/receipts/{receipt}/document, /pdf, /void.pdf: the receipt as a
 *   web page, its kept PDF, and once voided its VOID copy;
 * - /portal/proofs/{proof}/file: the file of one of their own proofs.
 *
 * Each record is found among the user's own (Portal\PortalDocuments), so
 * anyone else's answers 404 like a missing one, and is checked against
 * TenantProfilePolicy::downloadAsTenant before a signed link
 * (App\Media\MediaUrls, expiring after `media.url_lifetime_minutes`) is
 * made. They print the tenant's phone and email, or show the payer's bank
 * details, so none of them opens while GoProperty staff impersonate
 * (PLATFORM_CONSOLE.md § 6, as the members' downloads). Throttled per user
 * (routes).
 */
final class PortalDocumentController
{
    #[BlockedWhileImpersonating]
    public function invoicePdf(Request $request, int $bill, PortalDocuments $documents, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $documents->invoicePdf($this->user($request), $bill));
    }

    #[BlockedWhileImpersonating]
    public function contract(Request $request, int $lease, PortalDocuments $documents, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $documents->contractPdf($this->user($request), $lease));
    }

    #[BlockedWhileImpersonating]
    public function receipt(Request $request, int $receipt, PortalDocuments $documents): Response
    {
        $html = $documents->receiptPage($this->user($request), $receipt);
        abort_if($html === null, 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    #[BlockedWhileImpersonating]
    public function receiptPdf(Request $request, int $receipt, PortalDocuments $documents, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $documents->receiptPdf($this->user($request), $receipt));
    }

    #[BlockedWhileImpersonating]
    public function receiptVoidPdf(Request $request, int $receipt, PortalDocuments $documents, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $documents->receiptPdf($this->user($request), $receipt, void: true));
    }

    #[BlockedWhileImpersonating]
    public function proofFile(Request $request, int $proof, PortalDocuments $documents, MediaUrls $urls): RedirectResponse
    {
        return $this->send($urls, $documents->proofFile($this->user($request), $proof));
    }

    private function send(MediaUrls $urls, ?Media $media): RedirectResponse
    {
        abort_if($media === null, 404);

        return redirect()->away($urls->temporary($media))->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
