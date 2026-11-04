<?php

declare(strict_types=1);

namespace App\Privacy\Consents;

use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\User;
use App\Privacy\LegalDocuments;
use Illuminate\Http\Request;

/**
 * The consent records (P09-02; NDPA 2023 s. 26): the only writer of
 * `consent_records`. Each record says what the person agreed to (or
 * withdrew), which version of the text, when, from which IP address and
 * browser. Accepting the terms and the privacy policy is recorded at
 * signup and whenever an invitation is accepted (the pages say that going
 * on means agreeing to both, with links); product email is opt-in only and
 * recorded each time the person changes it (PLATFORM_CONSOLE.md § 5).
 */
final readonly class Consents
{
    public function __construct(
        private LegalDocuments $documents,
        private Request $request,
    ) {}

    /** The terms and privacy policy accepted, as published now. */
    public function acceptTerms(User $user, ConsentSource $source, ?Organization $organization = null): void
    {
        foreach ([ConsentDocument::Terms, ConsentDocument::Privacy] as $document) {
            $this->record($user, $document, true, $source, $organization, $this->documents->version($document));
        }
    }

    /** Product email opted in or out; nothing is written when the choice did not change. */
    public function setMarketingEmail(User $user, bool $granted, ConsentSource $source): bool
    {
        if ($this->marketingEmail($user) === $granted) {
            return false;
        }

        $this->record($user, ConsentDocument::MarketingEmail, $granted, $source, null, null);

        return true;
    }

    /** Whether the person has opted in to product email (never, until they do). */
    public function marketingEmail(User $user): bool
    {
        $latest = ConsentRecord::query()
            ->where('user_id', $user->id)
            ->where('document', ConsentDocument::MarketingEmail)
            ->orderByDesc('id')
            ->value('granted');

        return (bool) $latest;
    }

    private function record(User $user, ConsentDocument $document, bool $granted, ConsentSource $source, ?Organization $organization, ?string $version): void
    {
        $record = new ConsentRecord;
        $record->forceFill([
            'user_id' => $user->id,
            'organization_id' => $organization?->id,
            'document' => $document,
            'granted' => $granted,
            'version' => $version,
            'source' => $source,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'recorded_at' => now(),
        ])->save();
    }
}
