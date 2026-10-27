<?php

declare(strict_types=1);

namespace Tests\Support;

use GdImage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Compares two PDFs as pictures (BRANDING_DOCUMENTS.md § 5: a document must
 * render the same with dompdf and with Chrome). Each page is rasterised in
 * grey at a low resolution with poppler's pdftoppm (installed on CI by
 * .github/workflows/ci.yml, present on most Linux desktops), then compared
 * pixel by pixel, forgiving a shift of up to SHIFT pixels: the two drivers
 * place glyphs a fraction of a pixel apart and anti-alias differently, which
 * is not a difference anyone sees, while a wrapped line, a moved block or a
 * missing row moves whole areas and is caught. The score is the share of
 * pixels with no match within the shift, the worse of both directions.
 *
 * Calibration (P03-05a, the kit sample at 50 dpi): dompdf against Chrome
 * scored 0.02–0.07 % for the three layouts; the same page with one table
 * row fewer 0.9 %, two layouts of it 4.7–5.7 %. THRESHOLD (0.4 %) sits
 * between, with room for another Chromium build in Gotenberg.
 *
 * Tests only: it runs pdftoppm and pdftotext as processes, which the app
 * itself never does (shared hosts may disable proc_open).
 */
final class PdfVisualDiff
{
    public const int DPI = 50;

    /** Pixels either way a pixel may move and still match. */
    public const int SHIFT = 2;

    /** Grey levels (0–255) two pixels may differ by and still match. */
    public const int TOLERANCE = 64;

    /** Largest share of unmatched pixels two renderings of the same page may have. */
    public const float THRESHOLD = 0.004;

    /** Why visual comparison cannot run here, or null when it can. */
    public static function unavailable(): ?string
    {
        foreach (['pdftoppm', 'pdftotext'] as $tool) {
            if ((new ExecutableFinder)->find($tool) === null) {
                return "{$tool} (poppler-utils) is not installed";
            }
        }

        return null;
    }

    /**
     * Each page as rows of grey levels.
     *
     * @return list<list<list<int>>>
     */
    public static function rasterise(string $pdf, int $dpi = self::DPI): array
    {
        $directory = sys_get_temp_dir().'/gp-pdf-diff-'.bin2hex(random_bytes(6));
        mkdir($directory);

        try {
            file_put_contents($directory.'/document.pdf', $pdf);
            self::run(['pdftoppm', '-r', (string) $dpi, '-gray', '-png', $directory.'/document.pdf', $directory.'/page']);

            $files = glob($directory.'/page-*.png') ?: [];
            natsort($files);

            return array_values(array_map(self::grey(...), $files));
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }

    /** The PDF's text, laid out (pdftotext -layout). */
    public static function text(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gp-pdf-text-');

        try {
            file_put_contents((string) $file, $pdf);

            return self::run(['pdftotext', '-layout', (string) $file, '-']);
        } finally {
            @unlink((string) $file);
        }
    }

    /**
     * The worst page's score (0–1); pages missing on one side count as 1.
     *
     * @return array{score: float, pages: array{0: int, 1: int}, scores: list<float>}
     */
    public static function compare(string $first, string $second): array
    {
        $a = self::rasterise($first);
        $b = self::rasterise($second);
        $scores = [];

        foreach (range(0, max(count($a), count($b)) - 1) as $page) {
            $scores[] = isset($a[$page], $b[$page])
                ? max(self::score($a[$page], $b[$page]), self::score($b[$page], $a[$page]))
                : 1.0;
        }

        return ['score' => max($scores), 'pages' => [count($a), count($b)], 'scores' => $scores];
    }

    /**
     * Share of pixels of $a with no pixel of $b within SHIFT that matches
     * within TOLERANCE. Pages must be the same size within a pixel or two
     * (A4 at 50 dpi; the drivers round the page size differently).
     *
     * @param  list<list<int>>  $a
     * @param  list<list<int>>  $b
     */
    public static function score(array $a, array $b): float
    {
        $height = min(count($a), count($b));
        $width = min(count($a[0] ?? []), count($b[0] ?? []));

        if ($height === 0 || $width === 0 || abs(count($a) - count($b)) > 2 || abs(count($a[0]) - count($b[0])) > 2) {
            return 1.0;
        }

        $unmatched = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $value = $a[$y][$x];

                if (abs($value - $b[$y][$x]) <= self::TOLERANCE) {
                    continue;
                }

                $matched = false;

                for ($dy = -self::SHIFT; $dy <= self::SHIFT && ! $matched; $dy++) {
                    $row = $b[$y + $dy] ?? null;

                    if ($row === null) {
                        continue;
                    }

                    for ($dx = -self::SHIFT; $dx <= self::SHIFT; $dx++) {
                        if (isset($row[$x + $dx]) && abs($value - $row[$x + $dx]) <= self::TOLERANCE) {
                            $matched = true;

                            break;
                        }
                    }
                }

                if (! $matched) {
                    $unmatched++;
                }
            }
        }

        return $unmatched / ($width * $height);
    }

    /**
     * Gotenberg's base URL when it answers its health check, else null.
     * CI runs it as a service (PDF_CHROME_URL); locally it is usually absent.
     */
    public static function gotenberg(): ?string
    {
        $url = config('pdf.chrome.url');
        $url = is_string($url) && $url !== '' ? rtrim($url, '/') : 'http://127.0.0.1:3000';

        try {
            $health = Http::timeout(3)->get($url.'/health');

            return $health->successful() && $health->json('status') === 'up' ? $url : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<list<int>> */
    private static function grey(string $file): array
    {
        $image = imagecreatefrompng($file);

        if (! $image instanceof GdImage) {
            throw new RuntimeException("pdftoppm wrote an unreadable page: {$file}");
        }

        imagepalettetotruecolor($image);
        $rows = [];

        for ($y = 0, $height = imagesy($image); $y < $height; $y++) {
            $row = [];

            for ($x = 0, $width = imagesx($image); $x < $width; $x++) {
                $row[] = (imagecolorat($image, $x, $y) >> 8) & 0xFF;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @param  list<string>  $command */
    private static function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }
}
