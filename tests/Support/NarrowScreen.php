<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
 *
 * inspect() (P03-11b, the tenant portal's pass on every page) reads a
 * second square beside it: how many visible tap targets (links, buttons,
 * form fields, anything focusable) the page has, and how many of them are
 * smaller than 44 × 44 CSS pixels (UX_DESIGN.md § 1). A form field inside a
 * label counts at the label's size; a link inside a sentence is exempt, as
 * in WCAG 2.5.8 (making it 44 px tall would break the line); targets off
 * the screen (the closed mobile sidebar, a skip link above the top) or
 * invisible (screen-reader-only text) are not counted.
 * standalone() turns an app page (Vite stylesheets, Livewire and Flux
 * scripts on this host, which Gotenberg's Chrome cannot reach) into one
 * self-contained file: the built stylesheets inlined with their fonts,
 * scripts left out — the layout is the CSS's.
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

    /**
     * How the page lays out in a viewport $width CSS pixels wide, and its
     * tap targets (see the class comment).
     *
     * @return array{viewport: int, overflow: int, targets: int, small: int}
     */
    public static function inspect(string $gotenberg, string $html, int $width = 360): array
    {
        $image = self::screenshot($gotenberg, self::withTargetProbe(self::withProbe($html)), $width);
        $layout = imagecolorat($image, 3, 3);
        $targets = imagecolorat($image, 11, 3);

        return [
            'viewport' => (($layout >> 8) & 0xFF) << 8 | ($layout & 0xFF),
            'overflow' => ($layout >> 16) & 0xFF,
            'targets' => (($targets >> 8) & 0xFF) << 8 | ($targets & 0xFF),
            'small' => ($targets >> 16) & 0xFF,
        ];
    }

    /**
     * The page as one self-contained HTML file: each stylesheet of the
     * Vite build (public/build) inlined, its fonts as data URIs (WOFF2
     * only), every external script and preload left out.
     */
    public static function standalone(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#<link\b[^>]*\bhref="[^"]*?/build/(assets/[^"?]+\.css)"[^>]*>#i',
            static fn (array $match): string => '<style>'.self::stylesheet(public_path('build/'.$match[1])).'</style>',
            $html,
        );
        $html = (string) preg_replace('#<link\b[^>]*\brel="(modulepreload|preload|prefetch)"[^>]*>#i', '', $html);

        return (string) preg_replace('#<script\b[^>]*\bsrc="[^"]*"[^>]*>\s*</script>#i', '', $html);
    }

    private static function stylesheet(string $path): string
    {
        $css = is_file($path) ? (string) file_get_contents($path) : throw new RuntimeException("The built stylesheet {$path} is missing: run npm run build.");

        // WOFF2 faces only: every browser that runs the app reads it, and each face is listed once per format.
        $css = (string) preg_replace('#@font-face\s*\{[^}]*url\(["\']?[^)"\']+\.woff["\']?\)[^}]*\}#i', '', $css);

        return (string) preg_replace_callback(
            '#url\(["\']?/build/(assets/[^)"\'?]+\.woff2)["\']?\)#i',
            static fn (array $match): string => 'url("data:font/woff2;base64,'.base64_encode((string) file_get_contents(public_path('build/'.$match[1]))).'")',
            $css,
        );
    }

    private static function screenshot(string $gotenberg, string $html, int $width): \GdImage
    {
        $png = Http::timeout(60)
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=utf-8'])
            ->post(rtrim($gotenberg, '/').'/forms/chromium/screenshot/html', [
                'width' => (string) $width,
                'height' => '900',
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

        return $image;
    }

    private static function withTargetProbe(string $html): string
    {
        $probe = <<<'HTML'
            <div id="gp-targets" style="position:fixed;left:8px;top:0;width:8px;height:8px;z-index:2147483647;background:#000000"></div>
            <script>
            (function () {
              var probe = document.getElementById('gp-targets');
              var selector = 'a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=link], [role=tab], [role=menuitem], [tabindex]:not([tabindex="-1"])';
              function inSentence(link) {
                return Array.prototype.some.call(link.parentNode.childNodes, function (node) {
                  return node !== link && node.nodeType === 3 && node.textContent.trim() !== '';
                });
              }
              function measure() {
                var total = 0, small = 0;
                document.querySelectorAll(selector).forEach(function (el) {
                  if (el.id === 'gp-probe' || el.id === 'gp-targets') { return; }
                  var box = el.getBoundingClientRect();
                  var style = window.getComputedStyle(el);
                  if (box.width < 2 || box.height < 2 || style.visibility === 'hidden' || Number(style.opacity) === 0) { return; }
                  if (box.right <= 0 || box.left >= window.innerWidth || box.bottom <= 0) { return; }
                  total++;
                  if (el.tagName === 'A' && style.display === 'inline' && inSentence(el)) { return; }
                  var label = el.closest('label');
                  var width = box.width, height = box.height;
                  if (label) { var around = label.getBoundingClientRect(); width = Math.max(width, around.width); height = Math.max(height, around.height); }
                  if (width < 43.5 || height < 43.5) { small++; el.setAttribute('data-gp-small', Math.round(width) + 'x' + Math.round(height)); }
                });
                total = Math.min(65535, total);
                probe.style.background = 'rgb(' + Math.min(255, small) + ',' + (total >> 8) + ',' + (total & 255) + ')';
              }
              function loop() { measure(); window.requestAnimationFrame(loop); }
              window.addEventListener('load', measure);
              loop();
            })();
            </script>
            HTML;

        return Str::contains($html, '</body>') ? Str::replaceLast('</body>', $probe.'</body>', $html) : $html.$probe;
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
