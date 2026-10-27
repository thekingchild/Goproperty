<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Random\Randomizer;

/**
 * Payment references (ONBOARDING_COMPLIANCE.md § 4.1; DATABASE_SCHEMA.md § 4
 * `bills.payment_reference`): the short code a tenant puts in a bank
 * transfer's narration, which statement matching (P03-07b) looks for.
 *
 * "GP-" and 7 characters of Crockford's base 32 (digits and capitals
 * without I, L, O and U, so it reads aloud and types without confusion),
 * drawn from the system's secure random source: 35 bits, about 34 billion
 * references, so a guessed one rarely belongs to a bill (the public pay
 * page, P03-05c, is rate limited too). Unique across every organization:
 * the bills table's unique index decides, and Bills\BillIssuer draws again
 * on the rare collision. Typed references are forgiving (normalize()).
 */
final readonly class PaymentReferences
{
    public const string PREFIX = 'GP';

    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int LENGTH = 7;

    private Randomizer $random;

    public function __construct(?Randomizer $random = null)
    {
        // The default engine is the CSPRNG (Random\Engine\Secure).
        $this->random = $random ?? new Randomizer;
    }

    /** A new reference, not yet checked against the bills. */
    public function generate(): string
    {
        return self::PREFIX.'-'.$this->random->getBytesFromString(self::ALPHABET, self::LENGTH);
    }

    /**
     * The stored form of a reference as someone typed it ("gp 7kq2m9x",
     * "GP7KQ2M9X"), or null when it cannot be one: case, spaces and dashes
     * are ignored, and O, I and L read as 0, 1 and 1.
     */
    public static function normalize(string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[\s\-]+/', '', $input));

        if (! str_starts_with($compact, self::PREFIX)) {
            return null;
        }

        $code = strtr(substr($compact, strlen(self::PREFIX)), ['O' => '0', 'I' => '1', 'L' => '1']);

        if (strlen($code) !== self::LENGTH || strspn($code, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return self::PREFIX.'-'.$code;
    }
}
