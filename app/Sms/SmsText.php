<?php

declare(strict_types=1);

namespace App\Sms;

use Illuminate\Support\Str;

/**
 * One-page SMS text (P05-02; ARCHITECTURE.md § 6.7: "SMS templates kept
 * under 160 chars"). A page holds 160 characters only when every character
 * is in the GSM 7-bit basic set; one other character (₦, an accented
 * letter, an emoji) makes the whole message UCS-2, 70 characters a page.
 * So SMS text is kept to that set: ₦ becomes "NGN ", names are
 * transliterated (Adébáyọ̀ → Adebayo), anything else is dropped.
 */
final class SmsText
{
    public const int PAGE = 160;

    /** GSM 03.38 basic characters (no escape characters, which count twice). */
    private const string GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** The text made one-line and GSM-only. */
    public static function plain(string $text): string
    {
        $text = str_replace('₦', 'NGN ', $text);
        $text = Str::ascii($text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $allowed = self::characters();
        $kept = '';

        foreach (mb_str_split($text) as $character) {
            if (isset($allowed[$character])) {
                $kept .= $character;
            }
        }

        return trim($kept);
    }

    /**
     * The characters outside the GSM basic set, as typed.
     *
     * @return list<string>
     */
    public static function outside(string $text): array
    {
        $allowed = self::characters();

        return array_values(array_unique(array_filter(mb_str_split($text), static fn (string $character): bool => ! isset($allowed[$character]))));
    }

    /** +2348031112222 → +234 ••• 2222 */
    public static function mask(string $number): string
    {
        return strlen($number) > 8 ? substr($number, 0, 4).' ••• '.substr($number, -4) : '•••';
    }

    /** @return array<string, true> */
    private static function characters(): array
    {
        static $set = null;

        return $set ??= array_fill_keys(mb_str_split(self::GSM), true);
    }
}
