<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\AppVersion;
use App\Installer\Web\WizardStep;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Renders a wizard page inside the installer's split shell (INSTALLER.md
 * § 5, UX_DESIGN.md § 3): the brand panel with the step list beside the
 * step's form. The views are plain PHP with the static install.css, so they
 * render without compiling Blade into storage/ and without the Vite
 * manifest, which the server check has not confirmed yet.
 */
final class InstallerPage
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly Config $config,
    ) {}

    /**
     * @param  WizardStep  $step  the step this page belongs to
     * @param  WizardStep  $reached  the furthest step the owner may open
     * @param  view-string  $view  the step's view, under installer/steps
     * @param  array<string, mixed>  $data
     */
    public function render(Request $request, WizardStep $step, WizardStep $reached, string $view, string $title, array $data = [], int $status = 200): Response
    {
        $name = $this->config->get('app.name');
        $name = is_string($name) && $name !== '' ? $name : 'GoProperty';
        $base = $request->getBaseUrl();
        $shared = [
            'name' => $name,
            'base' => $base,
            'token' => $request->hasSession() ? $request->session()->token() : null,
        ];

        $steps = [];

        foreach (WizardStep::cases() as $case) {
            $steps[] = [
                'label' => $case->label(),
                'number' => $case->number(),
                'state' => match (true) {
                    $case === $step => 'current',
                    $case->number() < $reached->number() => 'done',
                    default => 'upcoming',
                },
                // Steps already reached can be opened again from the list.
                'url' => $case !== WizardStep::Unlock && $case->number() <= $reached->number() ? $base.'/'.$case->path() : null,
            ];
        }

        $content = $this->views->make($view, $shared + $data)->render();

        return new Response($this->views->make('installer.layout', $shared + [
            'title' => $title,
            'version' => AppVersion::current(),
            'stylesheet' => $request->getBasePath().'/install.css',
            'steps' => $steps,
            'step' => $step,
            'content' => $content,
        ])->render(), $status, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
