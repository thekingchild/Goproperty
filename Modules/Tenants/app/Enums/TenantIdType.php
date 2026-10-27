<?php

declare(strict_types=1);

namespace Modules\Tenants\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The identity document on file for a tenant, `tenant_profiles.id_type`
 * (DATABASE_SCHEMA.md § 1), or none. The number itself is encrypted.
 * Numbers are compared written in capitals without spaces or dashes
 * (normalise()), the way they are kept.
 */
enum TenantIdType: string
{
    use HasValues;

    case Nin = 'nin';
    case Passport = 'passport';
    case DriversLicence = 'drivers_licence';
    case VotersCard = 'voters_card';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Nin => 'NIN',
            self::Passport => 'International passport',
            self::DriversLicence => 'Driver\'s licence',
            self::VotersCard => 'Voter\'s card',
            self::None => 'None',
        };
    }

    /** @return list<self> the documents a number can be kept for */
    public static function documents(): array
    {
        return [self::Nin, self::Passport, self::DriversLicence, self::VotersCard];
    }

    /**
     * The type an agency wrote ("NIN", "passport", "Drivers license", "PVC",
     * …), in any letter case, or null when it is none of them.
     */
    public static function fromInput(string $input): ?self
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($input));

        foreach (self::cases() as $case) {
            if ($key === preg_replace('/[^a-z]/', '', mb_strtolower($case->label())) || $key === str_replace('_', '', $case->value)) {
                return $case;
            }
        }

        return match ($key) {
            'nationalid', 'nationalidentitynumber', 'nationalidentificationnumber', 'nationalidcard', 'ninslip' => self::Nin,
            'intlpassport', 'internationalpassport', 'passport', 'passportnumber' => self::Passport,
            'driverslicense', 'driverlicence', 'driverlicense', 'drivinglicence', 'drivinglicense', 'dl' => self::DriversLicence,
            'voterscard', 'votercard', 'pvc', 'permanentvoterscard', 'votersid' => self::VotersCard,
            default => null,
        };
    }

    /** The number as it is kept: capitals, without spaces, dots or dashes. */
    public static function normalise(string $number): string
    {
        return mb_strtoupper((string) preg_replace('/[\s.\-\/]/', '', $number));
    }

    /** Whether a normalised number has this document's format. */
    public function accepts(string $number): bool
    {
        $pattern = match ($this) {
            // 11 digits (NIMC).
            self::Nin => '/^\d{11}$/',
            // A letter and 8 digits, such as A01234567 (NIS).
            self::Passport => '/^[A-Z]\d{8}$/',
            // FRSC: three letters for the state, then letters and digits, such as ABC12345AA12.
            self::DriversLicence => '/^[A-Z]{3}[A-Z0-9]{7,12}$/',
            // INEC's voter identification number: 19 letters and digits.
            self::VotersCard => '/^[A-Z0-9]{19}$/',
            self::None => null,
        };

        return $pattern !== null && preg_match($pattern, $number) === 1;
    }

    /** What the number should look like, for the agency. */
    public function formatHint(): string
    {
        return match ($this) {
            self::Nin => 'A NIN is 11 digits, such as 12345678901.',
            self::Passport => 'An international passport number is a letter and 8 digits, such as A01234567.',
            self::DriversLicence => 'A driver\'s licence number starts with three letters, such as ABC12345AA12.',
            self::VotersCard => 'A voter\'s card number (VIN) is 19 letters and digits, such as 90F5B0F8E7295346795.',
            self::None => 'Leave the ID number empty when there is no ID.',
        };
    }
}
