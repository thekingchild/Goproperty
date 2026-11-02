<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Marketing\PlanCatalogue;
use App\Marketing\PublicSite;
use App\Plans\Feature;
use App\Support\Money;
use Illuminate\Contracts\View\View;

/**
 * The public marketing pages (UX_DESIGN.md § 4): the landing page, the
 * pricing page (P06-11) and the privacy and terms pages, all built from the
 * published theme settings and the plan catalogue's live plans.
 */
final class MarketingController
{
    /** Units the pricing calculator starts on, and its top end. */
    private const int CALCULATOR_UNITS = 30;

    private const int CALCULATOR_MAX = 1000;

    public function home(PublicSite $site, PlanCatalogue $plans): View
    {
        return view('marketing.home', $this->shared($site) + $this->calculator($plans));
    }

    /**
     * The pricing page (P06-11; BILLING_PAYMENTS.md § 1.1–1.2): the
     * calculator and plan cards of the landing page, then every offered plan
     * side by side with its features and limits, all from the plan tables
     * at today's terms (a scheduled version shows from its date).
     */
    public function pricing(PublicSite $site, PlanCatalogue $plans): View
    {
        return view('marketing.pricing', $this->shared($site) + $this->calculator($plans) + [
            'features' => Feature::cases(),
        ]);
    }

    public function privacy(PublicSite $site): View
    {
        return view('marketing.legal', $this->shared($site) + [
            'heading' => 'Privacy policy',
            'body' => $site->theme()->string('site.privacy_policy'),
        ]);
    }

    public function terms(PublicSite $site): View
    {
        return view('marketing.legal', $this->shared($site) + [
            'heading' => 'Terms of service',
            'body' => $site->theme()->string('site.terms'),
        ]);
    }

    /** @return array<string, mixed> */
    private function calculator(PlanCatalogue $plans): array
    {
        $calcPlan = $plans->bestFor(self::CALCULATOR_UNITS);
        $perUnitNaira = (int) round($calcPlan->priceFor(self::CALCULATOR_UNITS)->kobo / self::CALCULATOR_UNITS / 100);

        return [
            'plans' => $plans,
            'calcUnits' => self::CALCULATOR_UNITS,
            'calcMax' => self::CALCULATOR_MAX,
            'calcPlan' => $calcPlan,
            // Whole naira, as the calculator script shows it.
            'calcPerUnit' => Money::kobo($perUnitNaira * 100),
        ];
    }

    /** @return array<string, mixed> */
    private function shared(PublicSite $site): array
    {
        $theme = $site->theme();
        $email = $theme->string('theme.support_email');

        return [
            'theme' => $theme,
            'brand' => $theme->name(),
            'socialLinks' => $site->socialLinks(),
            'trialUrl' => route('register'),
            'demoUrl' => $email !== '' ? 'mailto:'.$email.'?subject='.rawurlencode('Book a demo') : route('home').'#start',
        ];
    }
}
