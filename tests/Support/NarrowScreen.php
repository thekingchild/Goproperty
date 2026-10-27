<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Measures a web page at a phone's width in real Chrome (UX_DESIGN.md § 1:
 * every customer and tenant screen works at 360 px), through Gotenberg's
 * screenshot route: the same Chrome the PDF tests use, which CI runs as a
 * service (orchestrator decision 6).
 *
 * A screenshot cannot say whether a page scrolls sideways, so a small
 * probe is added to the page: a fixed 8 × 8 px square in the top-left corner
 * whose colour encodes, on every frame, how many CSS pixels the document
 * is wider than the space beside the vertical scroll bar (red) and the
 * viewport's width (green and blue). Fixed and inside the viewport, it adds no width of its own. The
 * test reads that pixel back.
 */
final class NarrowScreen
{
    /**
     * How the page lays out in a viewport $width CSS pixels wide.
     *
     * @return array{viewport: int, overflow: int}
     */
    public static function measure(string $gotenberg, string $html, int $width = 360): array
    {
        $png = Http::timeout(60)
            ->attach('files', self::withProbe($html), 'index.html', ['Content-Type' => 'text/html; charset=utf-8'])
            ->post(rtrim($gotenberg, '/').'/forms/chromium/screenshot/html', [
                'width' => (string) $width,
                'height' => '900',
                // Clip to the device size: a full-page capture would widen the viewport to the page.
                'clip' => 'true',
                'format' => 'png',
                'waitDelay' => '1s',
            ])
            ->throw()
            ->body();

        $image = @imagecreatefromstring($png);

        if ($image === false) {
            throw new RuntimeException('Gotenberg did not return a PNG screenshot.');
        }

        imagepalettetotruecolor($image);
        $colour = imagecolorat($image, 3, 3);

        return [
            'viewport' => (($colour >> 8) & 0xFF) << 8 | ($colour & 0xFF),
            'overflow' => ($colour >> 16) & 0xFF,
        ];
    }

    private static function withProbe(string $html): string
    {
        $probe = <<<'HTML'
            <div id="gp-probe" style="position:fixed;left:0;top:0;width:8px;height:8px;z-index:2147483647;background:#000000"></div>
            <script>
            (function () {
              var probe = document.getElementById('gp-probe');
              function measure() {
                var root = document.documentElement;
                var over = Math.max(0, Math.min(255, Math.max(root.scrollWidth, document.body.scrollWidth) - root.clientWidth));
                var width = Math.min(65535, window.innerWidth);
                probe.style.background = 'rgb(' + over + ',' + (width >> 8) + ',' + (width & 255) + ')';
              }
              function loop() { measure(); window.requestAnimationFrame(loop); }
              window.addEventListener('resize', measure);
              window.addEventListener('load', measure);
              loop();
            })();
            </script>
            HTML;

        return str_contains($html, '</body>') ? str_replace('</body>', $probe.'</body>', $html) : $html.$probe;
    }
}
