<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The CSS rules of BRANDING_DOCUMENTS.md § 5 for document views: no
 * flexbox, no grid, no CSS variables (dompdf supports none of them, so a
 * page using them prints differently with the two drivers), and only the
 * font weights bundled for both drivers (App\Documents\DocumentFonts).
 */
final class DocumentCssLint
{
    /** @var array<string, string> rule => pattern */
    private const array RULES = [
        'flexbox' => '/(?<![\w-])(display\s*:\s*(inline-)?flex\b|flex(-[a-z]+)*\s*:|(align|justify)-(items|content|self)\s*:|place-(items|content|self)\s*:|gap\s*:)/i',
        'grid' => '/(?<![\w-])(display\s*:\s*(inline-)?grid\b|grid(-[a-z]+)*\s*:)/i',
        'CSS variables' => '/(var\(\s*--|(?<![\w-])--[a-z0-9_-]+\s*:)/i',
    ];

    /**
     * Each problem as "rule: excerpt".
     *
     * @return list<string>
     */
    public static function problems(string $source): array
    {
        $problems = [];

        foreach (self::RULES as $rule => $pattern) {
            if (preg_match_all($pattern, $source, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $problems[] = $rule.': '.trim($match);
                }
            }
        }

        if (preg_match_all('/font-weight\s*:\s*([^;}"\']+)/i', $source, $weights) > 0) {
            foreach ($weights[1] as $weight) {
                $weight = trim($weight);

                // Blade expressions are checked in the rendered pages.
                if (! str_contains($weight, '{{') && ! in_array(strtolower($weight), ['400', '600', '700', '800', 'normal', 'bold'], true)) {
                    $problems[] = 'font weight: '.$weight;
                }
            }
        }

        return $problems;
    }
}
