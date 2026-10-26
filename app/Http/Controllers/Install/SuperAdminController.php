<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\InstallationException;
use App\Installer\InstallationSteps;
use App\Installer\Web\TwoFactorEnrolment;
use App\Installer\Web\WizardProgress;
use App\Installer\Web\WizardStep;
use App\Models\PlatformStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 6 (INSTALLER.md § 5): the first staff account, then its
 * authenticator app, set up right here so the owner never meets a console
 * that will not open. In three screens:
 *
 * 1. name, work email and password (12+ characters, not in a known breach);
 *    refused if a staff account exists, unless it is the same email and
 *    password (resuming after a timeout);
 * 2. QR code and manual key, confirmed with a 6-digit code;
 * 3. 8 recovery codes, with Download and Copy, and a required "I've saved
 *    my recovery codes" tick.
 *
 * The secret waits in the encrypted installer session until confirmed.
 */
final class SuperAdminController extends WizardController
{
    public function show(Request $request, TwoFactorEnrolment $enrolment): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Admin)) !== null) {
            return $blocked;
        }

        $session = $request->session();
        $codes = $session->get(WizardProgress::RECOVERY_CODES);

        if (is_array($codes)) {
            return $this->screen($request, WizardStep::Admin, 'installer.steps.recovery-codes', $this->messages($request) + ['codes' => array_values(array_filter($codes, 'is_string'))]);
        }

        $pending = $this->enrolment($request);

        if ($pending !== null) {
            [$staff, $secret] = $pending;

            return $this->screen($request, WizardStep::Admin, 'installer.steps.two-factor', $this->messages($request) + [
                'email' => $staff->email,
                'secret' => $secret,
                'qr' => $enrolment->qrCode($staff, $secret),
                'uri' => $enrolment->uri($staff, $secret),
            ]);
        }

        return $this->screen($request, WizardStep::Admin, 'installer.steps.admin', $this->messages($request) + [
            'ready' => $this->progress->superAdminReady(),
        ]);
    }

    public function store(Request $request, InstallationSteps $steps, TwoFactorEnrolment $enrolment): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Admin)) !== null) {
            return $blocked;
        }

        $name = self::input($request, 'name');
        $email = self::input($request, 'email');
        $password = $request->input('password');
        $password = is_string($password) ? $password : '';

        if ($password !== $request->input('password_confirmation')) {
            return $this->back($request, WizardStep::Admin, 'The two passwords differ.', 'password_confirmation');
        }

        try {
            $staff = $steps->createSuperAdmin($name, $email, $password);
        } catch (ValidationException $exception) {
            return $this->invalid($request, WizardStep::Admin, $exception);
        } catch (InstallationException $exception) {
            return $this->back($request, WizardStep::Admin, $exception->getMessage());
        }

        $this->grant->renew();

        // Resuming with an authenticator already set up: nothing to enrol.
        if ($staff->two_factor_secret !== null && $staff->two_factor_confirmed_at !== null) {
            return $this->completed($request, WizardStep::Finish);
        }

        $request->session()->put(WizardProgress::ENROLMENT, ['staff' => $staff->id, 'secret' => $enrolment->newSecret()]);

        return $this->to($request, WizardStep::Admin);
    }

    public function confirm(Request $request, TwoFactorEnrolment $enrolment): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Admin)) !== null) {
            return $blocked;
        }

        $pending = $this->enrolment($request);

        if ($pending === null) {
            return $this->to($request, WizardStep::Admin);
        }

        [$staff, $secret] = $pending;
        $code = $request->input('code');

        if (! is_string($code) || ! $enrolment->verify($secret, $code)) {
            return $this->back($request, WizardStep::Admin, 'That code is not right. Check the time on your phone is set automatically, then type the code shown now.', 'code');
        }

        $codes = $enrolment->confirm($staff, $secret);
        $request->session()->forget(WizardProgress::ENROLMENT);
        $request->session()->put(WizardProgress::RECOVERY_CODES, $codes);
        $this->grant->renew();
        Log::channel('install')->info('First staff account enrolled two-factor sign-in');

        return $this->to($request, WizardStep::Admin);
    }

    public function savedCodes(Request $request): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Admin)) !== null) {
            return $blocked;
        }

        if (! $request->session()->has(WizardProgress::RECOVERY_CODES)) {
            return $this->to($request, WizardStep::Admin);
        }

        if (! $request->boolean('saved')) {
            return $this->back($request, WizardStep::Admin, 'Tick the box once your recovery codes are stored somewhere safe.', 'saved');
        }

        $request->session()->forget(WizardProgress::RECOVERY_CODES);

        return $this->completed($request, WizardStep::Finish);
    }

    /** @return array{0: PlatformStaff, 1: string}|null the account and secret being enrolled */
    private function enrolment(Request $request): ?array
    {
        $pending = $request->session()->get(WizardProgress::ENROLMENT);

        if (! is_array($pending) || ! is_int($pending['staff'] ?? null) || ! is_string($pending['secret'] ?? null)) {
            return null;
        }

        $staff = PlatformStaff::query()->find($pending['staff']);

        return $staff instanceof PlatformStaff ? [$staff, $pending['secret']] : null;
    }

    private static function input(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }
}
