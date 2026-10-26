<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\Web\InstallerGrant;
use App\Installer\Web\WizardProgress;
use App\Installer\Web\WizardStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared by the wizard's steps (INSTALLER.md § 5). Every step runs behind
 * the installer grant (RequireInstallerGrant); steps open in order, a
 * completed step renews the grant, and the wizard stops when the database
 * settings no longer match the ones the install started with (§ 6).
 */
abstract class WizardController
{
    public function __construct(
        protected readonly InstallerPage $page,
        protected readonly WizardProgress $progress,
        protected readonly InstallerGrant $grant,
    ) {}

    /**
     * Null when this step may be shown; otherwise the response to send
     * instead (the recovery page, or the step the owner has reached).
     */
    protected function blocked(Request $request, WizardStep $step): ?Response
    {
        if ($this->progress->settingsChanged()) {
            return $this->page->render($request, $step, $step, 'installer.steps.stopped', 'Install stopped', [], 409);
        }

        $current = $this->progress->current($request->session());

        return $step->number() > $current->number() ? $this->to($request, $current) : null;
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    protected function screen(Request $request, WizardStep $step, string $view, array $data = [], int $status = 200): Response
    {
        return $this->page->render($request, $step, $this->progress->current($request->session()), $view, $step->label(), $data, $status);
    }

    /** A step was completed: the grant runs another 60 minutes. */
    protected function completed(Request $request, WizardStep $next): RedirectResponse
    {
        $this->grant->renew();

        return $this->to($request, $next);
    }

    protected function to(Request $request, WizardStep $step): RedirectResponse
    {
        return new RedirectResponse($request->getBaseUrl().'/'.$step->path(), 303);
    }

    /** Back to a step's page with a message, keeping what was typed except passwords. */
    protected function back(Request $request, WizardStep $step, string $message, string $key = 'form', bool $error = true): RedirectResponse
    {
        $request->session()->flash($error ? 'installer.error' : 'installer.status', [$key => $message]);
        $request->session()->flashInput($request->except(['_token', 'db_password', 'mail_password', 'storage_secret', 'password', 'password_confirmation', 'code']));

        return $this->to($request, $step);
    }

    /** Back with the first message of each invalid field. */
    protected function invalid(Request $request, WizardStep $step, ValidationException $exception): RedirectResponse
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors[$field] = (string) ($messages[0] ?? '');
        }

        $request->session()->flash('installer.error', $errors);
        $request->session()->flashInput($request->except(['_token', 'db_password', 'mail_password', 'storage_secret', 'password', 'password_confirmation', 'code']));

        return $this->to($request, $step);
    }

    /**
     * Messages flashed by back() and invalid(), and what was typed.
     *
     * @return array{errors: array<string, string>, status: array<string, string>, old: array<string, mixed>}
     */
    protected function messages(Request $request): array
    {
        $session = $request->session();
        $errors = $session->get('installer.error', []);
        $status = $session->get('installer.status', []);
        $old = $session->get('_old_input', []);

        return [
            'errors' => is_array($errors) ? array_filter($errors, 'is_string') : [],
            'status' => is_array($status) ? array_filter($status, 'is_string') : [],
            'old' => is_array($old) ? $old : [],
        ];
    }
}
