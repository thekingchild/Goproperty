<?php

declare(strict_types=1);

namespace App\Documents;

/** Reads a PDF's page count from its bytes (no PDF library needed). */
final class PdfPages
{
    public static function count(string $pdf): int
    {
        // The page tree's root holds the total (the largest /Count of a /Pages node).
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s', $pdf, $matches) > 0) {
            $counts = array_map('intval', array_filter([...$matches[1], ...$matches[2]], static fn (string $value): bool => $value !== ''));

            if ($counts !== []) {
                return max($counts);
            }
        }

        // Objects in compressed streams: count the page objects that are visible.
        $pages = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);

        return $pages === false ? 0 : $pages;
    }
}
