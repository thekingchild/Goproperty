<?php

declare(strict_types=1);

use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\KitSample;
use App\Documents\RenderTarget;
use Illuminate\Support\Facades\File;
use Tests\Support\DocumentCssLint;

require_once __DIR__.'/../../Support/documents.php';

/*
 * P03-05a acceptance: document views use table layouts and simple CSS only
 * (BRANDING_DOCUMENTS.md § 5; AGENT.md § 4): no flexbox, grid or CSS
 * variables, so dompdf and Chrome print them alike.
 */

/** @return list<string> every document view, Core's and the modules' */
function documentViews(): array
{
    $files = File::allFiles(resource_path('views/documents'));

    foreach (glob(base_path('Modules/*/resources/views/documents'), GLOB_ONLYDIR) ?: [] as $directory) {
        $files = [...$files, ...File::allFiles($directory)];
    }

    return array_values(array_map(fn (SplFileInfo $file): string => $file->getPathname(), array_filter($files, fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))));
}

describe('acceptance: a lint bans flex, grid and CSS variables in document views', function () {
    it('finds none in any document view', function () {
        $views = documentViews();
        $problems = [];

        foreach ($views as $view) {
            foreach (DocumentCssLint::problems((string) file_get_contents($view)) as $problem) {
                $problems[] = str_replace(base_path().'/', '', $view).' — '.$problem;
            }
        }

        expect($views)->toContain(resource_path('views/documents/partials/styles.blade.php'), resource_path('views/documents/kit-sample.blade.php'))
            ->and($problems)->toBe([]);
    });

    it('finds none in the pages as rendered, for every layout and target', function (DocumentLayout $layout, RenderTarget $target) {
        $html = app(DocumentRenderer::class)->html(KitSample::document(sampleBrand($layout)), $target);

        expect(DocumentCssLint::problems($html))->toBe([]);
    })->with(DocumentLayout::cases())->with(RenderTarget::cases());

    it('catches each banned construct', function (string $css, string $rule) {
        expect(DocumentCssLint::problems($css))->not->toBe([])
            ->and(DocumentCssLint::problems($css)[0])->toStartWith($rule);
    })->with([
        'display flex' => ['.a { display: flex; }', 'flexbox'],
        'inline-flex' => ['<div style="display:inline-flex">', 'flexbox'],
        'flex shorthand' => ['.a { flex: 1 1 auto; }', 'flexbox'],
        'flex-direction' => ['.a { flex-direction: column; }', 'flexbox'],
        'justify-content' => ['.a { justify-content: space-between; }', 'flexbox'],
        'gap' => ['.a { gap: 8px; }', 'flexbox'],
        'display grid' => ['.a{display:grid}', 'grid'],
        'grid-template' => ['.a { grid-template-columns: 1fr 1fr; }', 'grid'],
        'var()' => ['.a { color: var(--brand); }', 'CSS variables'],
        'custom property' => [':root { --brand: #0e6b5c; }', 'CSS variables'],
        'unbundled weight' => ['.a { font-weight: 500; }', 'font weight'],
    ]);

    it('leaves ordinary document CSS alone', function () {
        expect(DocumentCssLint::problems('table { border-collapse: collapse; } .flexible-note { font-weight: 600; } .grid-like td { padding: 0; } .x { text-transform: uppercase; }'))->toBe([]);
    });
});
