<?php

declare(strict_types=1);

use App\Models\SubscriptionPlan;
use App\Plans\Feature;
use App\Plans\FeatureValue;
use App\Theme\ThemeManager;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../Support/plans.php';

/*
 * P06-11: the pricing page (UX_DESIGN.md § 4, BILLING_PAYMENTS.md § 1.1–1.2).
 * Acceptance, first half: "Pricing page reads live plan data". Every price,
 * limit and switch on /pricing comes from the plan tables at today's terms,
 * so a console edit shows at once and a scheduled version from its date.
 * The second half (checkout starts a subscription) is proven in
 * Modules/SaasBilling/tests/Feature/NewOrganizationCheckoutTest.php.
 */

beforeEach(function () {
    seedStaffRoles();
    $this->finance = staffNamed('Funmi', ['finance-admin']);
});

/**
 * The comparison table's cells for one row, by plan column, as text.
 *
 * @return array<string, string>
 */
function compareRow(string $html, string $rowLabel): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR);
    $xpath = new DOMXPath($document);
    $columns = [];

    foreach ($xpath->query('//table[@data-test="compare"]/thead/tr/th[@data-plan-column]') ?: [] as $th) {
        $columns[] = $th instanceof DOMElement ? $th->getAttribute('data-plan-column') : '';
    }

    foreach ($xpath->query('//table[@data-test="compare"]/tbody/tr') ?: [] as $row) {
        $header = $xpath->query('./th[@scope="row"]', $row)?->item(0);

        if ($header === null || trim($header->textContent) !== $rowLabel) {
            continue;
        }

        $cells = [];

        foreach ($xpath->query('./td', $row) ?: [] as $i => $cell) {
            // A tick or dash is hidden from screen readers; its words are what the cell says.
            $words = $xpath->query('./span[@class="sr-only"]', $cell)?->item(0) ?? $cell;
            $cells[$columns[$i]] = trim(preg_replace('/\s+/', ' ', $words->textContent) ?? '');
        }

        return $cells;
    }

    throw new LogicException("No row {$rowLabel}.");
}

function pricingPage(): string
{
    catalogue();

    return (string) test()->get('/pricing')->assertOk()->getContent();
}

it('shows every offered plan with its prices, features and limits from the plan tables', function () {
    $html = pricingPage();

    expect(compareRow($html, 'Base price a month'))->toBe(['starter' => '₦10,000', 'growth' => '₦35,000', 'scale' => '₦85,000', 'enterprise' => 'from ₦250,000'])
        ->and(compareRow($html, 'Base price a year'))->toBe(['starter' => '₦100,000', 'growth' => '₦350,000', 'scale' => '₦850,000', 'enterprise' => 'Custom contract'])
        ->and(compareRow($html, 'Units included'))->toBe(['starter' => '10', 'growth' => '50', 'scale' => '200', 'enterprise' => '1,000'])
        ->and(compareRow($html, 'Each extra unit a month'))->toBe(['starter' => '₦700', 'growth' => '₦500', 'scale' => '₦350', 'enterprise' => 'Custom'])
        ->and(compareRow($html, 'Free trial'))->toBe(['starter' => '14 days', 'growth' => '14 days', 'scale' => '14 days', 'enterprise' => '14 days'])
        ->and(compareRow($html, Feature::StaffUsers->label()))->toBe(['starter' => '3', 'growth' => '10', 'scale' => 'Unlimited', 'enterprise' => 'Unlimited'])
        ->and(compareRow($html, Feature::SmsCredits->label()))->toBe(['starter' => '100', 'growth' => '500', 'scale' => '2,000', 'enterprise' => '2,000'])
        ->and(compareRow($html, Feature::OwnPaystack->label()))->toBe(['starter' => 'Not included', 'growth' => 'Included', 'scale' => 'Included', 'enterprise' => 'Included'])
        ->and(compareRow($html, Feature::AgentAssignment->label()))->toBe(['starter' => 'Not included', 'growth' => 'Not included', 'scale' => 'Included', 'enterprise' => 'Included'])
        ->and(compareRow($html, 'Remove "Generated with GoProperty" from documents'))->toBe(['starter' => 'Not included', 'growth' => 'Not included', 'scale' => 'Included', 'enterprise' => 'Included']);

    $this->get('/pricing')
        ->assertSee('<title>Pricing', false)
        ->assertSeeInOrder(['Starter', '₦10,000', 'Growth', '₦35,000', 'Scale', '₦85,000', 'Enterprise', '₦250,000'])
        ->assertSee('Starter · ₦800 per unit a month · plus 7.5% VAT')
        ->assertSee('data-price-year="₦350,000"', false)
        ->assertSee('"pricing":{"plans":[', false);
});

it('reads live plan data: a console edit shows at once, new terms from their date, a withdrawn plan disappears', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $growth = SubscriptionPlan::query()->where('key', 'growth')->sole();
    $scale = SubscriptionPlan::query()->where('key', 'scale')->sole();

    // Name and pricing-page lines change at once.
    planEditor()->updateDetails($this->finance, $growth, ['name' => 'Growth Plus', 'highlights' => ['Everything in Starter, and more']]);
    // Nobody is on Growth: new terms may start today. Staff users 10 → 15, base ₦35,000 → ₦38,000.
    planEditor()->schedule($this->finance, $growth->refresh(), termsLike('growth', 3_800_000, features: [Feature::StaffUsers->value => FeatureValue::limit(15)]), CarbonImmutable::parse('2026-11-02', 'Africa/Lagos'), 'Launch review');
    // Scale's new price is scheduled for 1 December.
    planEditor()->schedule($this->finance, $scale, termsLike('scale', 9_000_000), CarbonImmutable::parse('2026-12-01', 'Africa/Lagos'), 'Price review');

    $html = pricingPage();
    expect(compareRow($html, 'Base price a month'))->toMatchArray(['growth' => '₦38,000', 'scale' => '₦85,000'])
        ->and(compareRow($html, Feature::StaffUsers->label()))->toMatchArray(['growth' => '15'])
        ->and($html)->toContain('Growth Plus')->toContain('Everything in Starter, and more');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:05', 'Africa/Lagos'));
    expect(compareRow(pricingPage(), 'Base price a month'))->toMatchArray(['scale' => '₦90,000']);

    // A plan no longer offered to new signups is not on the page.
    planEditor()->updateDetails($this->finance, $scale->refresh(), ['is_active' => false]);
    $html = pricingPage();
    expect(compareRow($html, 'Base price a month'))->toBe(['starter' => '₦10,000', 'growth' => '₦38,000', 'enterprise' => 'from ₦250,000'])
        ->and($html)->not->toContain('data-plan="scale"')
        ->and($html)->not->toContain('plan=scale');
});

it('links each plan to signup with its plan and billing period; Enterprise to a conversation', function () {
    $html = pricingPage();

    foreach (['starter', 'growth', 'scale'] as $key) {
        $monthly = e(route('register', ['plan' => $key, 'cycle' => 'monthly']));
        $yearly = e(route('register', ['plan' => $key, 'cycle' => 'annual']));

        expect($html)->toContain('href="'.$monthly.'" data-href-month="'.$monthly.'" data-href-year="'.$yearly.'"');
    }

    expect($html)->not->toContain('plan=enterprise')
        ->and($html)->toContain('Talk to us');

    // The landing page's plan cards carry the plan too.
    $this->get('/')->assertSee(e(route('register', ['plan' => 'growth', 'cycle' => 'monthly'])), false);
});

it('is linked from the landing page and listed in the sitemap', function () {
    $this->get('/pricing')->assertSee('href="'.route('pricing').'" aria-current="page"', false)
        ->assertSee('href="'.route('home').'#features"', false);

    $this->get('/')->assertSee('<a href="#pricing">Pricing</a>', false);

    $themes = app(ThemeManager::class);
    $themes->saveDraft(['seo.indexing_enabled' => true]);
    $themes->publish();

    $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.url('/pricing').'</loc>', false);
});
