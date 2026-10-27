<?php

declare(strict_types=1);

namespace App\Documents;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;
use RuntimeException;

/**
 * QR codes for documents' verify boxes (BRANDING_DOCUMENTS.md § 2 item 8,
 * § 3 item 7). bacon/bacon-qr-code encodes; the image is drawn here with
 * GD as a two-colour PNG, because bacon's own image back end needs Imagick
 * (not on shared hosts) and dompdf and Chrome both show a PNG data URI the
 * same way. Error correction M (15%): a printed, folded receipt still scans.
 */
final class QrCode
{
    /** Modules of white around the code (the verify box adds more padding). */
    public const int QUIET_ZONE = 2;

    /** Pixels a module: sharp when the PDF is printed at 87 CSS px wide. */
    public const int SCALE = 8;

    /** The PNG bytes of a QR code holding $text. */
    public static function png(string $text, int $scale = self::SCALE): string
    {
        $grid = self::modules($text);
        $modules = count($grid);
        $size = max(1, ($modules + 2 * self::QUIET_ZONE) * max(1, $scale));

        $image = imagecreate($size, $size);

        if ($image === false) {
            throw new RuntimeException('GD could not make the QR code image.');
        }

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $dark = (int) imagecolorallocate($image, 15, 23, 42);
        imagefill($image, 0, 0, $white);

        foreach ($grid as $y => $row) {
            foreach ($row as $x => $isDark) {
                if ($isDark) {
                    $left = ($x + self::QUIET_ZONE) * max(1, $scale);
                    $top = ($y + self::QUIET_ZONE) * max(1, $scale);
                    imagefilledrectangle($image, $left, $top, $left + max(1, $scale) - 1, $top + max(1, $scale) - 1, $dark);
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /** data:image/png;base64,… for <img src> in web pages and PDFs alike. */
    public static function dataUri(string $text): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($text));
    }

    /**
     * The module grid (true = dark), without the quiet zone: for tests and
     * anything that wants to draw the code itself.
     *
     * @return list<list<bool>>
     */
    public static function modules(string $text): array
    {
        if ($text === '' || strlen($text) > 512) {
            throw new InvalidArgumentException('A QR code holds 1 to 512 bytes here.');
        }

        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8', null, false)->getMatrix();
        $rows = [];

        for ($y = 0; $y < $matrix->getWidth(); $y++) {
            $row = [];

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
