<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Documents\Verification\DocumentVerifiers;
use App\Documents\Verification\VerificationCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public verify page (BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7):
 * the QR code on an invoice or receipt opens /verify/{code}; the printed
 * "visit …/verify and enter" leads to /verify, a form for the code.
 *
 * No sign-in and no policy: the code itself is the only key (50 random
 * bits, VerificationCodes), the page is rate-limited per IP
 * (`document-verification`) and shows only what VerifiedDocument carries:
 * the issuer, the document, its date and amount, and whether it is valid,
 * voided or superseded — never tenants or bank details. The code's
 * organization and module are found by DocumentVerifiers (one unscoped read
 * of `document_verifications`, then the module's resolver inside that
 * organization). An unknown, malformed or unresolvable code is a plain 404,
 * the same for all, so codes cannot be probed.
 */
final class VerifyDocumentController
{
    public function form(Request $request): Response|RedirectResponse
    {
        $typed = $request->query('code');

        if (is_string($typed) && trim($typed) !== '') {
            $code = VerificationCodes::normalize($typed);

            if ($code !== null) {
                return redirect()->route('documents.verify', ['code' => $code]);
            }
        }

        return $this->private(response()->view('pages.documents.verify-form', [
            'typed' => is_string($typed) ? mb_substr($typed, 0, 40) : '',
            'invalid' => is_string($typed) && trim($typed) !== '',
        ], is_string($typed) && trim($typed) !== '' ? 422 : 200));
    }

    public function show(string $code, DocumentVerifiers $verifiers): Response|RedirectResponse
    {
        $normal = VerificationCodes::normalize($code);

        abort_if($normal === null, 404);

        // One address per code (as typed: lower case, dashes, O for 0 …).
        if ($normal !== $code) {
            return redirect()->route('documents.verify', ['code' => $normal], 301);
        }

        $document = $verifiers->find($normal);

        abort_if($document === null, 404);

        return $this->private(response()->view('pages.documents.verify', [
            'document' => $document,
            'code' => VerificationCodes::format($normal),
        ]));
    }

    /** The status may change (paid, voided): never cached; not for search engines. */
    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
