<?php

declare(strict_types=1);

/*
 * Builds the fonts bundled for documents (BRANDING_DOCUMENTS.md § 5) into
 * public/fonts/documents, so dompdf and Chrome print with the same files:
 *
 * - Inter from @fontsource/inter (npm, OFL-1.1): the WOFF 1.0 files of the
 *   weights the document styles use (400, 600, 700, 800, 400 italic), turned
 *   back into TrueType (dompdf reads only TrueType). Fontsource splits Inter
 *   into subsets; "latin" holds the text and "latin-ext" the ₦ sign. Both
 *   drivers fall back glyph by glyph along the font-family list, so the
 *   documents name "Inter", then "Inter Ext".
 * - DejaVu Sans Mono Bold and DejaVu Serif (regular, bold) from dompdf's own
 *   copy (Bitstream Vera licence), for codes and the classic layout's
 *   headings: dompdf has them built in, Chrome gets them from here.
 *
 * Run from the repository root after `npm ci`: php scripts/fonts/build-document-fonts.php
 */

$root = dirname(__DIR__, 2);
$target = $root.'/public/fonts/documents';
$inter = $root.'/node_modules/@fontsource/inter/files';
$dejavu = $root.'/vendor/dompdf/dompdf/lib/fonts';

if (! is_dir($inter) || ! is_dir($dejavu)) {
    fwrite(STDERR, "Run npm ci and composer install first.\n");
    exit(1);
}

@mkdir($target, 0755, true);

$faces = ['400-normal', '600-normal', '700-normal', '800-normal', '400-italic'];

foreach (['latin' => 'Inter', 'latin-ext' => 'InterExt'] as $subset => $name) {
    foreach ($faces as $face) {
        $ttf = woffToSfnt((string) file_get_contents("{$inter}/inter-{$subset}-{$face}.woff"));
        file_put_contents("{$target}/{$name}-{$face}.ttf", $ttf);
    }
}

foreach (['DejaVuSansMono-Bold.ttf', 'DejaVuSerif.ttf', 'DejaVuSerif-Bold.ttf'] as $file) {
    copy("{$dejavu}/{$file}", "{$target}/{$file}");
}

copy($root.'/node_modules/@fontsource/inter/LICENSE', $target.'/LICENSE-Inter.txt');

echo "Fonts written to {$target}\n";

/** WOFF 1.0 → TrueType: inflates each table and rebuilds the table directory. */
function woffToSfnt(string $woff): string
{
    $header = unpack('Nsignature/Nflavor/Nlength/nnumTables', $woff);

    if ($header === false || $header['signature'] !== 0x774F4646) {
        throw new RuntimeException('Not a WOFF 1.0 file.');
    }

    $count = $header['numTables'];
    $tables = [];

    for ($i = 0; $i < $count; $i++) {
        $entry = unpack('a4tag/Noffset/NcompLength/NorigLength/NorigChecksum', substr($woff, 44 + $i * 20, 20));
        $raw = substr($woff, $entry['offset'], $entry['compLength']);
        $data = $entry['compLength'] < $entry['origLength'] ? zlib_decode($raw) : $raw;

        if (! is_string($data) || strlen($data) !== $entry['origLength']) {
            throw new RuntimeException("Table {$entry['tag']} did not inflate.");
        }

        $tables[$entry['tag']] = [$data, $entry['origChecksum']];
    }

    ksort($tables, SORT_STRING);
    $selector = (int) floor(log($count, 2));
    $searchRange = (2 ** $selector) * 16;
    $out = pack('Nnnnn', $header['flavor'], $count, $searchRange, $selector, $count * 16 - $searchRange);
    $offset = 12 + 16 * $count;
    $directory = '';
    $body = '';

    foreach ($tables as $tag => [$data, $checksum]) {
        $directory .= pack('a4NNN', $tag, $checksum, $offset, strlen($data));
        $padded = $data.str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $body .= $padded;
        $offset += strlen($padded);
    }

    return $out.$directory.$body;
}
