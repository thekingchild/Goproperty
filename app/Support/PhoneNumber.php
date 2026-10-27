<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone numbers as typed in Nigeria, normalised to E.164 (+234…) so
 * "0803 123 4567", "+234 803 123 4567", "+234 (0) 803 123 4567" and
 * "2348031234567" are one
 * sign-in name (UX_DESIGN.md § 3.1). Numbers with another country code
 * are kept when written with "+" or "00".
 */
final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $compact = preg_replace('/[\s().\-]/', '', trim($input)) ?? '';

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        $international = str_starts_with($compact, '+');
        $digits = ltrim($compact, '+');

        // "+234 (0) 803 …": the trunk 0 written after the country code.
        if (str_starts_with($digits, '2340') && strlen($digits) === 14) {
            $digits = '234'.substr($digits, 4);
        }

        if ($digits === '' || preg_match('/^\d+$/D', $digits) !== 1) {
            return null;
        }

        $number = match (true) {
            str_starts_with($digits, '234') && strlen($digits) === 13 => '+'.$digits,
            $international => strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null,
            str_starts_with($digits, '0') && strlen($digits) === 11 => '+234'.substr($digits, 1),
            strlen($digits) === 10 && in_array($digits[0], ['7', '8', '9'], true) => '+234'.$digits,
            default => null,
        };

        // Nigerian mobile and fixed numbers: +234 then 10 digits, not starting with 0.
        if ($number !== null && str_starts_with($number, '+234') && preg_match('/^\+234[1-9]\d{9}$/D', $number) !== 1) {
            return null;
        }

        return $number;
    }

    /**
     * A Nigerian mobile number (070, 080, 081, 090, 091), normalised to
     * +234…, or null. Signup asks for one (ONBOARDING_COMPLIANCE.md § 2);
     * it is a contact and sign-in name only, never sent an SMS code.
     */
    public static function nigerianMobile(?string $input): ?string
    {
        $number = self::normalize($input);

        return $number !== null && preg_match('/^\+234(70|80|81|90|91)\d{8}$/D', $number) === 1 ? $number : null;
    }
}
