<?php

declare(strict_types=1);

namespace App\Documents\Verification;

use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Random\Randomizer;
use RuntimeException;

/**
 * Verification codes for issued documents (BRANDING_DOCUMENTS.md § 2 item
 * 8, § 3 item 7): printed in the verify box and held by its QR code, which
 * opens /verify/{code}.
 *
 * A code is 10 characters of Crockford's base 32 (digits and capitals
 * without I, L, O and U, so it reads aloud and types without confusion),
 * drawn from the system's secure random source: 50 bits, about 10^15
 * codes, so nobody finds a real one by guessing (the verify page is rate
 * limited too) and a code says nothing about the organization, the
 * document or the order of issue. Printed as two groups, `7KQ2M-X9P4D`.
 * Typed codes are forgiving: case, spaces and dashes are ignored, and O, I
 * and L read as 0, 1 and 1.
 *
 * issue() records the code in `document_verifications` (unique across every
 * kind of document), retrying on the rare collision, inside the current
 * organization: call it in the transaction that issues the document.
 */
final readonly class VerificationCodes
{
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int LENGTH = 10;

    private const int ATTEMPTS = 5;

    private Randomizer $random;

    public function __construct(
        private OrganizationContext $context,
        ?Randomizer $random = null,
    ) {
        // The default engine is the CSPRNG (Random\Engine\Secure).
        $this->random = $random ?? new Randomizer;
    }

    /**
     * A new code for a document of this kind (the key its module registered
     * in DocumentVerifiers), recorded for the current organization.
     */
    public function issue(string $kind): string
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,39}$/', $kind) !== 1) {
            throw new InvalidArgumentException('A document kind is a short lower-case key such as "invoice".');
        }

        $this->context->get();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $code = $this->generate();

            try {
                DocumentVerification::query()->create(['kind' => $kind, 'code' => $code]);

                return $code;
            } catch (UniqueConstraintViolationException) {
                // Another document has it (1 in 10^15 a code); draw again.
            }
        }

        throw new RuntimeException('Could not find a free verification code.');
    }

    /** A random code, not yet recorded anywhere. */
    public function generate(): string
    {
        return $this->random->getBytesFromString(self::ALPHABET, self::LENGTH);
    }

    /**
     * The stored form of a code as someone typed or scanned it, or null when
     * it cannot be one.
     */
    public static function normalize(string $input): ?string
    {
        $code = strtr(strtoupper((string) preg_replace('/[\s\-]+/', '', $input)), ['O' => '0', 'I' => '1', 'L' => '1']);

        if (strlen($code) !== self::LENGTH || strspn($code, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return $code;
    }

    /** "7KQ2M-X9P4D", as printed. */
    public static function format(string $code): string
    {
        $normal = self::normalize($code);

        return $normal !== null ? substr($normal, 0, 5).'-'.substr($normal, 5) : $code;
    }

    /** The verify page's address for a code, held by the QR code. */
    public static function url(string $code): string
    {
        return route('documents.verify', ['code' => self::normalize($code) ?? $code]);
    }
}
