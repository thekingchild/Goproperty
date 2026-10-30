<?php

declare(strict_types=1);

namespace App\Plans;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * An action the organization's plan does not include, or that would go over
 * one of its limits (P06-01b; BILLING_PAYMENTS.md § 1.2). Thrown by
 * PlanGate (route middleware and the Livewire hook) and again by the
 * services. Answered as 403 with a message that names the plan to move to.
 */
final class PlanLimitReached extends AccessDeniedHttpException {}
