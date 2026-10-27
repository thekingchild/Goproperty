<?php

declare(strict_types=1);

namespace App\Documents;

/** Small helpers for the CSS document views print. */
final class DocumentCss
{
    /** Height of the footer band in the page's bottom margin (PDF targets), in CSS px. */
    public const int FOOTER_HEIGHT = 40;

    /** A4 at 96 dpi is 1122.5 px tall: 1123 would spill onto a second sheet. */
    public const int PAGE_HEIGHT = 1122;

    public const int PAGE_WIDTH = 794;

    /**
     * A quoted CSS string (for `content:` in @page margin boxes): every
     * character but letters and digits is hex-escaped, so no organization
     * name or number can close the string or the style block. Each escape
     * ends with the one space CSS consumes after an escape.
     */
    public static function string(string $text): string
    {
        $escaped = '';

        foreach (mb_str_split($text) as $character) {
            $escaped .= preg_match('/^[A-Za-z0-9]$/', $character) === 1
                ? $character
                : '\\'.str_pad(dechex((int) mb_ord($character)), 6, '0', STR_PAD_LEFT).' ';
        }

        return '"'.$escaped.'"';
    }
}
