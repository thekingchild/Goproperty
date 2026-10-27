<?php

declare(strict_types=1);

namespace App\Documents\Verification;

use App\Models\Organization;

/**
 * Finds an issued document by its verification code, for one kind of
 * document (a module implements it in its own code and registers it into
 * DocumentVerifiers from its service provider, like import targets).
 *
 * Called inside the issuing organization's context (DocumentVerifiers has
 * already found it from the code), so the module reads its own
 * organization-scoped records as usual. Returns null when it no longer
 * knows the code; the page then answers 404 like an unknown code.
 */
interface VerificationResolver
{
    public function resolve(string $code, Organization $organization): ?VerifiedDocument;
}
